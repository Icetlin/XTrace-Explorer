<?php

namespace App\Service;

/**
 * All xdebug trace regex patterns in one place.
 * Each case value IS the pattern — use ->match($line) or preg_match(TraceRegex::X->value, ...).
 */
enum TraceRegex: string
{
    // Full xdebug call line: "    0.0036   444040   -> SomeClass->method($a = 1) /file.php:12"
    // Group 1 = indent spaces before "->", group 2 = everything after "-> " (sig + args + file suffix)
    case CallLine = '/^\s+[\d.]+\s+\d+([ ]*)->\s+(.+)/';

    // Full xdebug call line with end-of-line anchor (stricter form used when reading known complete lines)
    // Group 1 = indent spaces, group 2 = call body
    case CallLineStrict = '/^\s+[\d.]+\s+\d+([ ]*)->\s+(.+)$/';

    // Extracts time and memory from the start of any xdebug call line
    // Group 1 = time in seconds (float), group 2 = memory in bytes (int)
    case CallLineTimeMem = '/^\s+([\d.]+)\s+(\d+)\s+->/';


    // Return-value line: "   >=> someValue"
    // Group 1 = indent spaces (same count as the originating call), group 2 = the returned value
    case ReturnLine = '/^([ ]*)>=>\s*(.+)$/';

    // Detects "TraceableEventDispatcher->dispatch" signature (outermost dispatch, has $eventName in args)
    // We deliberately exclude plain EventDispatcher->dispatch which sits deeper without event name
    case DispatchCall = '/TraceableEventDispatcher->dispatch$/';

    // Extracts the string event name from a dispatch call arg: $eventName = 'kernel.request'
    // Group 1 = the event name string
    case EventName = '/\$eventName\s*=\s*\'([^\']+)\'/';

    // Extracts the event class when dispatched by object instead of string: $event = class Foo\BarEvent
    // Group 1 = fully-qualified class name
    case EventClass = '/\$event\s*=\s*class\s+([\w\\\\]+)/';

    // Extracts voter class from AccessDecisionManager->addVoterVote($voter = class App\Foo\VoterName { ... })
    // Group 1 = fully-qualified voter class name
    case VoterClass = '/\$voter\s*=\s*(?:class\s+)?([\w\\\\]+)\s*[{\[]/';

    // Extracts the attributes array from addVoterVote: $attributes = [0 => 'SOME_PERMISSION', ...]
    // Group 1 = raw array body (further parsed with preg_match_all for quoted strings)
    case VoterAttrs = '/\$attributes\s*=\s*\[([^\]]*)\]/';

    // Extracts the vote integer from addVoterVote: $vote = -1|0|1
    // Group 1 = the signed integer (-1 = DENIED, 0 = ABSTAIN, 1 = GRANTED)
    case VoterResult = '/,\s*\$vote\s*=\s*(-?\d+)/';

    // Matches a PascalCase token (potential class name) in a favourite-search pattern
    // Used to route PascalCase patterns to sig-only matching (avoids false positives in object dumps)
    case PascalCaseWord = '/^[A-Z][A-Za-z0-9]+$/';

    // Cookie name field inside an xdebug object dump: $name = 'sio_u'
    // Group 1 = the cookie name
    case CookieName = '/\$name\s*=\s*\'([^\']+)\'/';

    // Cookie value field inside an xdebug object dump: $value = 'abc123'
    // Group 1 = the cookie value
    case CookieValue = '/\$value\s*=\s*\'([^\']+)\'/';

    // RedirectResponse target URL from constructor arg: $url = 'https://...'
    // Group 1 = the URL
    case RedirectUrl = '/\$url\s*=\s*\'([^\']+)\'/';

    // RedirectResponse target URL from object property dump: targetUrl = 'https://...'
    // Group 1 = the URL
    case RedirectTargetUrl = '/targetUrl\s*=\s*\'([^\']+)\'/';

    // HTTP status code from Response->setStatusCode call: $code = 302
    // Group 1 = the 3-digit status code
    case StatusCode = '/\$code\s*=\s*(\d{3})/';

    // Status code from a VarDumper-style object dump: statusCode = 200
    // Group 1 = the 3-digit status code (used as a fallback when setStatusCode is not found)
    case StatusCodeDump = '/statusCode\s*=\s*(\d{3})/';

    // Trace file header timestamp: "TRACE START [2026-05-30 20:34:36.703988]"
    // Group 1 = the full datetime string
    case TraceStart = '/TRACE START \[([^]]+)]/';

    // REQUEST_METHOD value in the $_SERVER superglobal dump: 'REQUEST_METHOD' => 'POST'
    // Group 1 = HTTP method string
    case ServerRequestMethod = "/'REQUEST_METHOD'\s*=>\s*'([^']+)'/";

    // REQUEST_URI value: 'REQUEST_URI' => '/api/foo/bar'
    // Group 1 = raw URI path (may include query string)
    case ServerRequestUri = "/'REQUEST_URI'\s*=>\s*'([^']+)'/";

    // HTTP_HOST header value: 'HTTP_HOST' => 'example.com'
    // Group 1 = the host string
    case ServerHttpHost = "/'HTTP_HOST'\s*=>\s*'([^']+)'/";

    // QUERY_STRING value (may be empty): 'QUERY_STRING' => ''
    // Group 1 = raw query string
    case ServerQueryString = "/'QUERY_STRING'\s*=>\s*'([^']*)'/";

    // HTTP_COOKIE header: 'HTTP_COOKIE' => 'sio_u=abc; other=xyz'
    // Group 1 = raw cookie header value (further split on ; = pairs)
    case ServerHttpCookie = "/'HTTP_COOKIE'\s*=>\s*'([^']+)'/";

    // User-Agent header: 'HTTP_USER_AGENT' => 'Mozilla/5.0 ...'
    // Group 1 = user agent string
    case ServerUserAgent = "/'HTTP_USER_AGENT'\s*=>\s*'([^']+)'/";

    // Client IP: 'REMOTE_ADDR' => '1.2.3.4'
    // Group 1 = IP address string
    case ServerRemoteAddr = "/'REMOTE_ADDR'\s*=>\s*'([^']+)'/";

    // Request float timestamp: 'REQUEST_TIME_FLOAT' => 1748631276.703988
    // Group 1 = float value (unix timestamp with microseconds)
    case ServerRequestTimeFloat = "/'REQUEST_TIME_FLOAT'\s*=>\s*([\d.]+)/";

    // Content-Type header: 'CONTENT_TYPE' => 'application/json'
    // Group 1 = MIME type string
    case ServerContentType = "/'CONTENT_TYPE'\s*=>\s*'([^']+)'/";

    // Referer header: 'HTTP_REFERER' => 'https://...'
    // Group 1 = referer URL
    case ServerReferer = "/'HTTP_REFERER'\s*=>\s*'([^']+)'/";

    // Single-arg extraction: "$varName = someValue" at the start of a top-level-split arg token
    // Group 1 = variable name (without $), group 2 = raw value
    case ArgAssignment = '/^\$(\w+)\s*=\s*(.+)$/';

    // Full arg raw value extraction when the entire line is known: "$name = value"
    // Group 1 = the raw value after "= "
    case ArgRawValue = '/^\$\w+\s*=\s*(.+)$/s';

    // String literal value (up to 200 chars, single-quoted, may be multiline via /s flag)
    // Group 1 = unquoted string content
    case StringLiteral = "/^'(.{0,200})'\s*$/s";

    // JWT token detection inside a string: starts with "ey" followed by base64url chars
    // Used to replace JWT content with '<JWT>' placeholder
    case JwtToken = '/^ey[A-Za-z0-9]/';

    // Scalar literal: TRUE, FALSE, NULL, integer, or float (case-insensitive)
    // Full match — no groups needed
    case ScalarLiteral = '/^(TRUE|FALSE|NULL|-?\d+\.?\d*)$/i';

    // Cookie object class declaration: "class ...SomeCookie { ..."
    // Used to identify cookie objects before extracting the $name field
    case CookieObjectClass = '/^class\s+[\w\\\\]*Cookie[\s{]/';

    // Any xdebug class dump: "class Foo\Bar { ..."
    // Group 1 = fully-qualified class name
    case ClassDump = '/^class\s+([\w\\\\]+)/';

    // Enum value dump: "enum Foo\Bar::CaseName" or "enum Foo\Bar::CaseName('val')"
    // Group 1 = FQCN, group 2 = case name
    case EnumDump = '/^enum\s+([\w\\\\]+)::([\w]+)/';

    // Object-body parser: "class Foo\Bar { fields... }"
    // Group 1 = FQCN, group 2 = body between outer braces
    case ObjectBody = '/^class\s+([\w\\\\]+)\s*\{(.+)\}\s*$/s';

    // Object field segment: optional visibility/readonly modifiers + optional type hint + $name = value
    // Group 1 = property name, group 2 = raw value
    case ObjectField = '/(?:(?:public|protected|private|static|readonly)\s+)*(?:[\w\\\\?|&]+\s+)?\$(\w+)\s*=\s*(.+)$/s';

    // Absolute PHP file path with line number at end of a call line: " /path/to/file.php:123"
    // Matched against the substring after the last " /" in the line
    case FilePathSuffix = '#^/[^\s]+\.php:\d+$#';

    // Short file path extractor: strips /var/www/.../src/ or /vendor/ prefix
    // Group 1 = "src" or "vendor", group 2 = everything after it
    case ShortFilePath = '#/(src|vendor)/(.+)$#';

    // Trailing closing paren removal from extracted args string: "...)" at end
    // Used to strip the outer ) after extracting args substring
    case TrailingParen = '/\)\s*$/';

    /**
     * Convenience wrapper: run this pattern against $subject, return whether it matched.
     * Matches are discarded — use preg_match(self::X->value, ...) when you need captures.
     */
    public function matches(string $subject): bool
    {
        return (bool) preg_match($this->value, $subject);
    }
}
