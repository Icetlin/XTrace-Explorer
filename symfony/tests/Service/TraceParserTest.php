<?php

namespace App\Tests\Service;

use App\Entity\TraceFile;
use App\Service\TraceParser;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TraceParser::class)]
class TraceParserTest extends TestCase
{
    private string $tmpDir;
    private string $tracesDir;

    protected function setUp(): void
    {
        $this->tmpDir   = sys_get_temp_dir() . '/trace_parser_test_' . uniqid();
        $this->tracesDir = $this->tmpDir . '/traces';
        mkdir($this->tracesDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpDir);
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) { @unlink($dir); return; }
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') continue;
            $this->rmrf($dir . '/' . $f);
        }
        rmdir($dir);
    }

    private function makeTraceFile(int $id): TraceFile
    {
        $tf = $this->createMock(TraceFile::class);
        $tf->method('getId')->willReturn($id);
        $tf->method('setStatus')->willReturnSelf();
        $tf->method('setProgress')->willReturnSelf();
        $tf->method('getStatus')->willReturn('parsing');
        return $tf;
    }

    private function makeEm(): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $conn = $this->createMock(\Doctrine\DBAL\Connection::class);
        $em->method('getConnection')->willReturn($conn);
        return $em;
    }

    private function parse(string $xtContent, int $id = 1): array
    {
        $xtFile = $this->tmpDir . '/trace.xt';
        file_put_contents($xtFile, $xtContent);

        $em     = $this->makeEm();
        $tf     = $this->makeTraceFile($id);
        $em->expects($this->atLeastOnce())->method('flush');

        $parser = new TraceParser($this->tracesDir, $em);
        $parser->parse($tf, $xtFile);

        $dir = $this->tracesDir . '/' . $id;
        return [
            'toc'        => json_decode(file_get_contents($dir . '/toc.json'), true),
            'skeleton'   => json_decode(file_get_contents($dir . '/skeleton.json'), true),
            'line_index' => json_decode(file_get_contents($dir . '/line_index.json'), true),
            'meta'       => json_decode(file_get_contents($dir . '/meta.json'), true),
        ];
    }

    // ── minimal trace ──────────────────────────────────────────────────────────

    private function minimalTrace(): string
    {
        return <<<'XT'
TRACE START [2026-01-01 12:00:00.000000]
    0.0001     10000   -> {main}() /var/www/public/index.php:0
    0.0010     20000     -> Symfony\Component\HttpKernel\HttpKernel->handle($request = class Symfony\Component\HttpFoundation\Request {}) /var/www/public/index.php:10
    0.0100     30000       -> Symfony\Component\HttpKernel\HttpKernel->handleRaw($request = class Symfony\Component\HttpFoundation\Request {}) /var/www/vendor/symfony/http-kernel/HttpKernel.php:84
    0.0200     35000       >=> class Symfony\Component\HttpFoundation\Response { public statusCode = 200 }
    0.0210     25000     >=> class Symfony\Component\HttpFoundation\Response { public statusCode = 200 }
    0.0220     15000   >=> class Symfony\Component\HttpFoundation\Response { public statusCode = 200 }
TRACE END   [2026-01-01 12:00:00.022000]
XT;
    }

    public function testParsedFilesAreCreated(): void
    {
        $this->parse($this->minimalTrace());
        $dir = $this->tracesDir . '/1';
        self::assertFileExists($dir . '/toc.json');
        self::assertFileExists($dir . '/skeleton.json');
        self::assertFileExists($dir . '/line_index.json');
        self::assertFileExists($dir . '/meta.json');
    }

    public function testMetaTotalLinesIsCorrect(): void
    {
        $out = $this->parse($this->minimalTrace());
        // 8 lines in the trace (including trailing newline counted by fgets)
        self::assertSame(8, $out['meta']['total_lines']);
    }

    public function testMetaRequestInfoExtracted(): void
    {
        $xt = <<<'XT'
TRACE START [2026-01-01 12:00:00.000000]
    0.0001     10000   -> {main}($argv = ['index.php'], $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/foo', 'HTTP_HOST' => 'example.com', 'QUERY_STRING' => '', 'REQUEST_TIME_FLOAT' => 1234567890.0]) /index.php:0
TRACE END   [2026-01-01 12:00:00.001000]
XT;
        $out = $this->parse($xt);
        self::assertSame('GET', $out['meta']['request']['method'] ?? null);
        self::assertSame('/api/foo', $out['meta']['request']['uri'] ?? null);
        self::assertSame('example.com', $out['meta']['request']['host'] ?? null);
    }

    public function testLineIndexContainsZeroEntry(): void
    {
        $out = $this->parse($this->minimalTrace());
        self::assertArrayHasKey('0', $out['line_index']);
        self::assertSame(0, $out['line_index']['0']);
    }

    public function testSkeletonNodesNotEmpty(): void
    {
        $out = $this->parse($this->minimalTrace());
        self::assertNotEmpty($out['skeleton']['nodes']);
        self::assertNotEmpty($out['skeleton']['roots']);
    }

    public function testSkeletonNodeHasExpectedFields(): void
    {
        $out   = $this->parse($this->minimalTrace());
        $nodes = $out['skeleton']['nodes'];
        $first = reset($nodes);
        self::assertArrayHasKey('sig', $first);
        self::assertArrayHasKey('depth', $first);
        self::assertArrayHasKey('first_line', $first);
        self::assertArrayHasKey('children', $first);
    }

    public function testSkeletonDepthIsCorrect(): void
    {
        $out    = $this->parse($this->minimalTrace());
        $nodes  = array_values($out['skeleton']['nodes']);
        // depth = strlen(indent spaces before "->") / 2
        // "{main}" has 4 spaces → depth=2, HttpKernel->handle has 6 spaces → depth=3, etc.
        $depths = array_column($nodes, 'depth');
        $min    = min($depths);
        $max    = max($depths);
        self::assertGreaterThanOrEqual(1, $min);
        self::assertGreaterThan($min, $max, 'Nested calls should have increasing depths');
    }

    public function testSkeletonSigStrippedOfArgs(): void
    {
        $out   = $this->parse($this->minimalTrace());
        $nodes = $out['skeleton']['nodes'];
        $sigs  = array_column($nodes, 'sig');
        // sig should not contain $request = ...
        foreach ($sigs as $sig) {
            self::assertStringNotContainsString('$request', $sig);
        }
    }

    // ── TOC (dispatch blocks) ──────────────────────────────────────────────────

    private function traceWithDispatch(): string
    {
        // Listener detection requires: WrappedListener->__invoke at depth N,
        // then the real listener at depth N+1 (first non-noise class).
        return <<<'XT'
TRACE START [2026-01-01 12:00:00.000000]
    0.0001     10000   -> {main}() /index.php:0
    0.0010     20000     -> Symfony\Component\HttpKernel\EventListener\TraceableEventDispatcher->dispatch($event = class Symfony\Component\HttpKernel\Event\RequestEvent {}, $eventName = 'kernel.request') /EventDispatcher.php:1
    0.0020     25000       -> Symfony\Component\EventDispatcher\EventDispatcher->callListeners($event = class Symfony\Component\HttpKernel\Event\RequestEvent {}) /TraceableEventDispatcher.php:2
    0.0030     28000         -> Symfony\Component\EventDispatcher\WrappedListener->__invoke($event = class Symfony\Component\HttpKernel\Event\RequestEvent {}) /EventDispatcher.php:3
    0.0040     30000           -> App\EventListener\MyListener->onKernelRequest($event = class Symfony\Component\HttpKernel\Event\RequestEvent {}) /MyListener.php:10
    0.0050     29000           >=> NULL
    0.0060     27000         >=> NULL
    0.0070     24000       >=> NULL
    0.0080     22000     >=> class Symfony\Component\HttpKernel\Event\RequestEvent {}
    0.0090     15000   >=> class Symfony\Component\HttpFoundation\Response { public statusCode = 200 }
TRACE END   [2026-01-01 12:00:00.009000]
XT;
    }

    public function testTocContainsDispatchBlock(): void
    {
        $out = $this->parse($this->traceWithDispatch());
        self::assertNotEmpty($out['toc'], 'TOC should have at least one dispatch entry');
    }

    public function testTocEntryHasEventName(): void
    {
        $out   = $this->parse($this->traceWithDispatch());
        $entry = $out['toc'][0];
        self::assertSame('kernel.request', $entry['event']);
    }

    public function testTocEntryHasListeners(): void
    {
        $out     = $this->parse($this->traceWithDispatch());
        $entry   = $out['toc'][0];
        self::assertArrayHasKey('listeners', $entry);
        self::assertNotEmpty($entry['listeners']);
    }

    public function testTocListenerHasSig(): void
    {
        $out      = $this->parse($this->traceWithDispatch());
        $listener = $out['toc'][0]['listeners'][0];
        self::assertArrayHasKey('sig', $listener);
        self::assertStringContainsString('MyListener', $listener['sig']);
    }

    // ── edge cases ─────────────────────────────────────────────────────────────

    public function testEmptyTraceProducesEmptyOutputs(): void
    {
        $xt = "TRACE START [2026-01-01 12:00:00.000000]\nTRACE END   [2026-01-01 12:00:00.001000]\n";
        $out = $this->parse($xt);
        self::assertEmpty($out['toc']);
        self::assertEmpty($out['skeleton']['nodes']);
        self::assertEmpty($out['skeleton']['roots']);
    }

    public function testProgressFileRemovedAfterParse(): void
    {
        $xtFile = $this->tmpDir . '/trace.xt';
        file_put_contents($xtFile, $this->minimalTrace());
        $parser = new TraceParser($this->tracesDir, $this->makeEm());
        $tf     = $this->makeTraceFile(42);
        $parser->parse($tf, $xtFile);
        self::assertFileDoesNotExist(sys_get_temp_dir() . '/parse-progress-42.txt');
    }

    public function testCancelFileStopsParseEarly(): void
    {
        // Write a large-ish trace so cancel fires mid-parse
        $lines = ["TRACE START [2026-01-01 12:00:00.000000]\n"];
        for ($i = 0; $i < 5000; $i++) {
            $lines[] = "    0.000{$i}     10000   -> App\\Foo->bar{$i}() /src/Foo.php:{$i}\n";
        }
        $lines[] = "TRACE END   [2026-01-01 12:00:00.001000]\n";
        $xtFile = $this->tmpDir . '/cancel_trace.xt';
        file_put_contents($xtFile, implode('', $lines));

        // Place cancel file BEFORE parsing starts
        $cancelFile = sys_get_temp_dir() . '/parse-cancel-99.txt';
        touch($cancelFile);

        $em = $this->makeEm();
        $tf = $this->makeTraceFile(99);
        $parser = new TraceParser($this->tracesDir, $em);
        $parser->parse($tf, $xtFile);

        // Cancel file should be consumed
        self::assertFileDoesNotExist($cancelFile);
        // Output dir should exist (created before cancel check)
        self::assertDirectoryExists($this->tracesDir . '/99');
    }
}
