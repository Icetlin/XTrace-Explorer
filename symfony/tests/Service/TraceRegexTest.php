<?php

namespace App\Tests\Service;

use App\Service\TraceRegex;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(TraceRegex::class)]
class TraceRegexTest extends TestCase
{
    // ── CallLine ────────────────────────────────────────────────────────────

    public function testCallLineMatchesTypicalXdebugLine(): void
    {
        // Realistic xdebug line: 4 spaces before "->" → group 1 = 4 spaces → depth = 4/2 = 2
        $line = '    0.0036   444040    -> Foo\Bar->method($a = 1) /src/Foo.php:42';
        self::assertSame(1, preg_match(TraceRegex::CallLine->value, $line, $m));
        self::assertSame(4, strlen($m[1]));
        self::assertSame(2, (int)(strlen($m[1]) / 2));
        self::assertStringStartsWith('Foo\Bar->method', $m[2]);
    }

    public function testCallLineDoesNotMatchReturnLine(): void
    {
        self::assertSame(0, preg_match(TraceRegex::CallLine->value, '   >=> 42'));
    }

    // ── CallLineStrict ──────────────────────────────────────────────────────

    public function testCallLineStrictMatchesCleanLine(): void
    {
        $line = '    13.1497   44955160                             -> Foo\Bar->method($a = \'v\') /path/file.php:123';
        self::assertSame(1, preg_match(TraceRegex::CallLineStrict->value, $line, $m));
    }

    public function testCallLineStrictMatchesWithTrailingNewline(): void
    {
        // In PHP, $ matches before \n even without /m — so strict variant still matches fgets() lines.
        // TraceIndex uses rtrim() before depth extraction, not regex anchoring, for this reason.
        $line = "    0.0001   1000     -> Foo->bar() /x.php:1\n";
        self::assertSame(1, preg_match(TraceRegex::CallLineStrict->value, $line));
    }

    // ── ReturnLine ──────────────────────────────────────────────────────────

    public function testReturnLineMatchesXdebugReturnFormat(): void
    {
        $line = '                        >=> 42';
        self::assertSame(1, preg_match(TraceRegex::ReturnLine->value, $line, $m));
        self::assertSame('                        ', $m[1]); // indent = same as originating call
        self::assertSame('42', $m[2]);
    }

    public function testReturnLineExtractsStringValue(): void
    {
        $line = '    >=> \'hello world\'';
        self::assertSame(1, preg_match(TraceRegex::ReturnLine->value, $line, $m));
        self::assertSame("'hello world'", $m[2]);
    }

    // ── DispatchCall ────────────────────────────────────────────────────────

    public function testDispatchCallMatchesTraceableEventDispatcher(): void
    {
        $sig = 'Symfony\Component\HttpKernel\EventListener\TraceableEventDispatcher->dispatch';
        self::assertSame(1, preg_match(TraceRegex::DispatchCall->value, $sig));
    }

    public function testDispatchCallDoesNotMatchPlainEventDispatcher(): void
    {
        $sig = 'Symfony\Component\EventDispatcher\EventDispatcher->dispatch';
        self::assertSame(0, preg_match(TraceRegex::DispatchCall->value, $sig));
    }

    public function testDispatchCallDoesNotMatchPartialSuffix(): void
    {
        // Must end exactly with "->dispatch", not contain it mid-string
        $sig = 'TraceableEventDispatcher->dispatchSomething';
        self::assertSame(0, preg_match(TraceRegex::DispatchCall->value, $sig));
    }

    // ── EventName ───────────────────────────────────────────────────────────

    public function testEventNameExtractsStringEventName(): void
    {
        $line = '    0.001   100   -> TraceableEventDispatcher->dispatch($event = ..., $eventName = \'kernel.request\') /x.php:1';
        self::assertSame(1, preg_match(TraceRegex::EventName->value, $line, $m));
        self::assertSame('kernel.request', $m[1]);
    }

    public function testEventNameDoesNotMatchWhenAbsent(): void
    {
        $line = '    0.001   100   -> EventDispatcher->dispatch($event = class Foo {}) /x.php:1';
        self::assertSame(0, preg_match(TraceRegex::EventName->value, $line));
    }

    // ── EventClass ──────────────────────────────────────────────────────────

    public function testEventClassExtractsFqcn(): void
    {
        $line = '    0.001   100   -> dispatch($event = class Symfony\Component\HttpKernel\Event\RequestEvent { ... })';
        self::assertSame(1, preg_match(TraceRegex::EventClass->value, $line, $m));
        self::assertSame('Symfony\Component\HttpKernel\Event\RequestEvent', $m[1]);
    }

    // ── VoterClass ──────────────────────────────────────────────────────────

    public function testVoterClassExtractsFromClassDump(): void
    {
        $line = '    -> addVoterVote($voter = class App\Security\FooVoter { ... }, ...)';
        self::assertSame(1, preg_match(TraceRegex::VoterClass->value, $line, $m));
        self::assertSame('App\Security\FooVoter', $m[1]);
    }

    public function testVoterClassExtractsWithoutClassKeyword(): void
    {
        // Some xdebug versions emit the FQCN without "class " prefix
        $line = '    -> addVoterVote($voter = App\Security\FooVoter { ... })';
        self::assertSame(1, preg_match(TraceRegex::VoterClass->value, $line, $m));
        self::assertSame('App\Security\FooVoter', $m[1]);
    }

    // ── VoterAttrs ──────────────────────────────────────────────────────────

    public function testVoterAttrsExtractsArrayBody(): void
    {
        $line = "    -> addVoterVote(\$voter = ..., \$attributes = [0 => 'ROLE_ADMIN', 1 => 'EDIT'], \$vote = 1)";
        self::assertSame(1, preg_match(TraceRegex::VoterAttrs->value, $line, $m));
        self::assertStringContainsString("'ROLE_ADMIN'", $m[1]);
        self::assertStringContainsString("'EDIT'", $m[1]);
    }

    // ── VoterResult ─────────────────────────────────────────────────────────

    #[DataProvider('voterResultProvider')]
    public function testVoterResultExtractsSignedInteger(string $line, int $expected): void
    {
        self::assertSame(1, preg_match(TraceRegex::VoterResult->value, $line, $m));
        self::assertSame($expected, (int) $m[1]);
    }

    public static function voterResultProvider(): array
    {
        return [
            'granted' => ["addVoterVote(\$voter = ..., \$attributes = [...], \$vote = 1)", 1],
            'denied'  => ["addVoterVote(\$voter = ..., \$attributes = [...], \$vote = -1)", -1],
            'abstain' => ["addVoterVote(\$voter = ..., \$attributes = [...], \$vote = 0)", 0],
        ];
    }

    // ── PascalCaseWord ──────────────────────────────────────────────────────

    #[DataProvider('pascalCaseProvider')]
    public function testPascalCaseWordMatches(string $input, bool $shouldMatch): void
    {
        $matched = (bool) preg_match(TraceRegex::PascalCaseWord->value, $input);
        self::assertSame($shouldMatch, $matched);
    }

    public static function pascalCaseProvider(): array
    {
        return [
            'class name'          => ['FooVoter', true],
            'class name with nums'=> ['OAuth2Handler', true],
            'snake_case'          => ['foo_voter', false],
            'lowercase'           => ['kernel.request', false],
            'starts with digit'   => ['2Factor', false],
            'has backslash'       => ['Foo\Bar', false],
        ];
    }

    // ── CookieName ──────────────────────────────────────────────────────────

    public function testCookieNameExtractsName(): void
    {
        $line = "class Symfony\Component\HttpFoundation\Cookie { protected \$name = 'sio_u'; protected \$value = 'abc' }";
        self::assertSame(1, preg_match(TraceRegex::CookieName->value, $line, $m));
        self::assertSame('sio_u', $m[1]);
    }

    // ── CookieValue ─────────────────────────────────────────────────────────

    public function testCookieValueExtractsValue(): void
    {
        $line = "class ...Cookie { protected \$name = 'sio_u'; protected \$value = 'tok123' }";
        self::assertSame(1, preg_match(TraceRegex::CookieValue->value, $line, $m));
        self::assertSame('tok123', $m[1]);
    }

    // ── RedirectUrl ─────────────────────────────────────────────────────────

    public function testRedirectUrlExtractsFromConstructorArg(): void
    {
        $line = "    -> RedirectResponse->__construct(\$url = 'https://example.com/dashboard') /x.php:1";
        self::assertSame(1, preg_match(TraceRegex::RedirectUrl->value, $line, $m));
        self::assertSame('https://example.com/dashboard', $m[1]);
    }

    // ── RedirectTargetUrl ───────────────────────────────────────────────────

    public function testRedirectTargetUrlExtractsFromDump(): void
    {
        $line = "class RedirectResponse { protected targetUrl = 'https://example.com/login' }";
        self::assertSame(1, preg_match(TraceRegex::RedirectTargetUrl->value, $line, $m));
        self::assertSame('https://example.com/login', $m[1]);
    }

    // ── StatusCode ──────────────────────────────────────────────────────────

    public function testStatusCodeExtractsFromSetStatusCodeCall(): void
    {
        $line = "    -> Response->setStatusCode(\$code = 302, ...) /x.php:1";
        self::assertSame(1, preg_match(TraceRegex::StatusCode->value, $line, $m));
        self::assertSame(302, (int) $m[1]);
    }

    public function testStatusCodeDoesNotMatchTwoDigit(): void
    {
        self::assertSame(0, preg_match(TraceRegex::StatusCode->value, '$code = 20'));
    }

    // ── StatusCodeDump ──────────────────────────────────────────────────────

    public function testStatusCodeDumpExtractsFromObjectBody(): void
    {
        $dump = "class Response { protected statusCode = 200; ... }";
        self::assertSame(1, preg_match(TraceRegex::StatusCodeDump->value, $dump, $m));
        self::assertSame(200, (int) $m[1]);
    }

    // ── TraceStart ──────────────────────────────────────────────────────────

    public function testTraceStartExtractsDatetime(): void
    {
        $line = 'TRACE START [2026-05-30 20:34:36.703988]';
        self::assertSame(1, preg_match(TraceRegex::TraceStart->value, $line, $m));
        self::assertSame('2026-05-30 20:34:36.703988', $m[1]);
    }

    // ── Server superglobal fields ────────────────────────────────────────────

    public function testServerRequestMethodExtractsMethod(): void
    {
        $line = "    -> ({main})( ..., 'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/foo') /x.php:1";
        self::assertSame(1, preg_match(TraceRegex::ServerRequestMethod->value, $line, $m));
        self::assertSame('POST', $m[1]);
    }

    public function testServerRequestUriExtractsUri(): void
    {
        $line = "  'REQUEST_URI' => '/api/customer/123', 'HTTP_HOST' => 'app.example.com'";
        self::assertSame(1, preg_match(TraceRegex::ServerRequestUri->value, $line, $m));
        self::assertSame('/api/customer/123', $m[1]);
    }

    public function testServerHttpHostExtractsHost(): void
    {
        $line = "  'HTTP_HOST' => 'app.example.com', 'QUERY_STRING' => ''";
        self::assertSame(1, preg_match(TraceRegex::ServerHttpHost->value, $line, $m));
        self::assertSame('app.example.com', $m[1]);
    }

    public function testServerQueryStringAllowsEmpty(): void
    {
        // QUERY_STRING may be an empty string — pattern uses [^']* not [^']+
        $line = "  'QUERY_STRING' => '', 'other' => 'x'";
        self::assertSame(1, preg_match(TraceRegex::ServerQueryString->value, $line, $m));
        self::assertSame('', $m[1]);
    }

    public function testServerHttpCookieExtractsCookieHeader(): void
    {
        $line = "  'HTTP_COOKIE' => 'sio_u=abc123; PHPSESSID=xyz'";
        self::assertSame(1, preg_match(TraceRegex::ServerHttpCookie->value, $line, $m));
        self::assertSame('sio_u=abc123; PHPSESSID=xyz', $m[1]);
    }

    public function testServerUserAgentExtractsUserAgent(): void
    {
        $line = "  'HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64)'";
        self::assertSame(1, preg_match(TraceRegex::ServerUserAgent->value, $line, $m));
        self::assertSame('Mozilla/5.0 (X11; Linux x86_64)', $m[1]);
    }

    public function testServerRemoteAddrExtractsIp(): void
    {
        $line = "  'REMOTE_ADDR' => '192.168.1.100'";
        self::assertSame(1, preg_match(TraceRegex::ServerRemoteAddr->value, $line, $m));
        self::assertSame('192.168.1.100', $m[1]);
    }

    public function testServerRequestTimeFloatExtractsTimestamp(): void
    {
        $line = "  'REQUEST_TIME_FLOAT' => 1748631276.703988";
        self::assertSame(1, preg_match(TraceRegex::ServerRequestTimeFloat->value, $line, $m));
        self::assertEqualsWithDelta(1748631276.703988, (float) $m[1], 0.000001);
    }

    public function testServerContentTypeExtractsMime(): void
    {
        $line = "  'CONTENT_TYPE' => 'application/json'";
        self::assertSame(1, preg_match(TraceRegex::ServerContentType->value, $line, $m));
        self::assertSame('application/json', $m[1]);
    }

    public function testServerRefererExtractsUrl(): void
    {
        $line = "  'HTTP_REFERER' => 'https://example.com/prev-page'";
        self::assertSame(1, preg_match(TraceRegex::ServerReferer->value, $line, $m));
        self::assertSame('https://example.com/prev-page', $m[1]);
    }

    // ── ArgAssignment ───────────────────────────────────────────────────────

    public function testArgAssignmentExtractsNameAndValue(): void
    {
        self::assertSame(1, preg_match(TraceRegex::ArgAssignment->value, '$eventName = \'kernel.request\'', $m));
        self::assertSame('eventName', $m[1]);
        self::assertSame("'kernel.request'", $m[2]);
    }

    public function testArgAssignmentDoesNotMatchWithoutDollar(): void
    {
        self::assertSame(0, preg_match(TraceRegex::ArgAssignment->value, 'eventName = \'foo\''));
    }

    // ── ArgRawValue ─────────────────────────────────────────────────────────

    public function testArgRawValueExtractsValueAfterAssignment(): void
    {
        self::assertSame(1, preg_match(TraceRegex::ArgRawValue->value, '$foo = class Bar { $x = 1 }', $m));
        self::assertSame('class Bar { $x = 1 }', $m[1]);
    }

    // ── StringLiteral ────────────────────────────────────────────────────────

    public function testStringLiteralMatchesShortString(): void
    {
        self::assertSame(1, preg_match(TraceRegex::StringLiteral->value, "'hello'", $m));
        self::assertSame('hello', $m[1]);
    }

    public function testStringLiteralMatchesEmptyString(): void
    {
        self::assertSame(1, preg_match(TraceRegex::StringLiteral->value, "''", $m));
        self::assertSame('', $m[1]);
    }

    public function testStringLiteralDoesNotMatchUnquoted(): void
    {
        self::assertSame(0, preg_match(TraceRegex::StringLiteral->value, 'hello'));
    }

    public function testStringLiteralDoesNotMatchBeyond200Chars(): void
    {
        $long = "'" . str_repeat('x', 201) . "'";
        self::assertSame(0, preg_match(TraceRegex::StringLiteral->value, $long));
    }

    // ── JwtToken ─────────────────────────────────────────────────────────────

    public function testJwtTokenMatchesEyPrefix(): void
    {
        self::assertSame(1, preg_match(TraceRegex::JwtToken->value, 'eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiIxMjM0In0.sig'));
    }

    public function testJwtTokenDoesNotMatchOrdinaryString(): void
    {
        self::assertSame(0, preg_match(TraceRegex::JwtToken->value, 'hello-world'));
    }

    // ── ScalarLiteral ────────────────────────────────────────────────────────

    #[DataProvider('scalarLiteralProvider')]
    public function testScalarLiteralMatches(string $input, bool $shouldMatch): void
    {
        $matched = (bool) preg_match(TraceRegex::ScalarLiteral->value, $input);
        self::assertSame($shouldMatch, $matched);
    }

    public static function scalarLiteralProvider(): array
    {
        return [
            'TRUE'         => ['TRUE', true],
            'FALSE'        => ['FALSE', true],
            'NULL'         => ['NULL', true],
            'integer'      => ['42', true],
            'negative int' => ['-7', true],
            'float'        => ['3.14', true],
            'string'       => ["'foo'", false],
            'class dump'   => ['class Foo {}', false],
        ];
    }

    // ── CookieObjectClass ────────────────────────────────────────────────────

    public function testCookieObjectClassMatchesCookieSuffix(): void
    {
        self::assertSame(1, preg_match(TraceRegex::CookieObjectClass->value, 'class Symfony\Component\HttpFoundation\Cookie { ... }'));
    }

    public function testCookieObjectClassDoesNotMatchNonCookieClass(): void
    {
        self::assertSame(0, preg_match(TraceRegex::CookieObjectClass->value, 'class App\Entity\User { ... }'));
    }

    // ── ClassDump ────────────────────────────────────────────────────────────

    public function testClassDumpExtractsFqcn(): void
    {
        self::assertSame(1, preg_match(TraceRegex::ClassDump->value, 'class Foo\Bar\Baz { ... }', $m));
        self::assertSame('Foo\Bar\Baz', $m[1]);
    }

    // ── EnumDump ─────────────────────────────────────────────────────────────

    public function testEnumDumpExtractsClassAndCase(): void
    {
        self::assertSame(1, preg_match(TraceRegex::EnumDump->value, 'enum App\Enum\Status::Active', $m));
        self::assertSame('App\Enum\Status', $m[1]);
        self::assertSame('Active', $m[2]);
    }

    // ── ObjectBody ───────────────────────────────────────────────────────────

    public function testObjectBodyExtractsClassAndBody(): void
    {
        $val = 'class Foo\Bar { protected int $id = 5; private string $name = \'x\' }';
        self::assertSame(1, preg_match(TraceRegex::ObjectBody->value, $val, $m));
        self::assertSame('Foo\Bar', $m[1]);
        self::assertStringContainsString('$id = 5', $m[2]);
    }

    public function testObjectBodyDoesNotMatchNonClass(): void
    {
        self::assertSame(0, preg_match(TraceRegex::ObjectBody->value, "'just a string'"));
    }

    // ── ObjectField ──────────────────────────────────────────────────────────

    #[DataProvider('objectFieldProvider')]
    public function testObjectFieldExtractsNameAndValue(string $segment, string $expectedName, string $expectedValue): void
    {
        self::assertSame(1, preg_match(TraceRegex::ObjectField->value, $segment, $m));
        self::assertSame($expectedName, $m[1]);
        self::assertSame($expectedValue, $m[2]);
    }

    public static function objectFieldProvider(): array
    {
        return [
            'public int'            => ['public int $id = 42', 'id', '42'],
            'protected nullable'    => ['protected ?string $email = \'foo@bar.com\'', 'email', "'foo@bar.com'"],
            'private readonly'      => ['private readonly bool $active = TRUE', 'active', 'TRUE'],
            'no visibility'         => ['$code = 200', 'code', '200'],
        ];
    }

    // ── FilePathSuffix ───────────────────────────────────────────────────────

    public function testFilePathSuffixMatchesAbsolutePHPPath(): void
    {
        self::assertSame(1, preg_match(TraceRegex::FilePathSuffix->value, '/var/www/src/Foo/Bar.php:123'));
    }

    public function testFilePathSuffixDoesNotMatchRelativePath(): void
    {
        self::assertSame(0, preg_match(TraceRegex::FilePathSuffix->value, 'src/Foo/Bar.php:123'));
    }

    public function testFilePathSuffixDoesNotMatchWithoutLineNumber(): void
    {
        self::assertSame(0, preg_match(TraceRegex::FilePathSuffix->value, '/var/www/src/Foo/Bar.php'));
    }

    // ── ShortFilePath ────────────────────────────────────────────────────────

    public function testShortFilePathExtractsSrcSegment(): void
    {
        $path = '/var/www/monolith-backend/src/Controller/FooController.php:42';
        self::assertSame(1, preg_match(TraceRegex::ShortFilePath->value, $path, $m));
        self::assertSame('src', $m[1]);
        self::assertSame('Controller/FooController.php:42', $m[2]);
    }

    public function testShortFilePathExtractsVendorSegment(): void
    {
        $path = '/var/www/monolith-backend/vendor/symfony/http-kernel/Kernel.php:100';
        self::assertSame(1, preg_match(TraceRegex::ShortFilePath->value, $path, $m));
        self::assertSame('vendor', $m[1]);
    }

    // ── TrailingParen ────────────────────────────────────────────────────────

    public function testTrailingParenRemovesClosingParen(): void
    {
        $result = preg_replace(TraceRegex::TrailingParen->value, '', '$a = 1, $b = 2)');
        self::assertSame('$a = 1, $b = 2', $result);
    }

    public function testTrailingParenIsNoopWhenNoTrailingParen(): void
    {
        $result = preg_replace(TraceRegex::TrailingParen->value, '', '$a = 1, $b = 2');
        self::assertSame('$a = 1, $b = 2', $result);
    }

    // ── matches() helper ─────────────────────────────────────────────────────

    public function testMatchesHelperReturnsTrueOnMatch(): void
    {
        self::assertTrue(TraceRegex::DispatchCall->matches('Foo\TraceableEventDispatcher->dispatch'));
    }

    public function testMatchesHelperReturnsFalseOnNoMatch(): void
    {
        self::assertFalse(TraceRegex::DispatchCall->matches('EventDispatcher->dispatch'));
    }
}
