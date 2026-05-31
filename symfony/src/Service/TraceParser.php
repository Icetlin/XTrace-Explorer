<?php

namespace App\Service;

use App\Entity\TraceFile;
use Doctrine\ORM\EntityManagerInterface;

class TraceParser
{
    private const SPARSE_EVERY = 500;

    // Format: "    0.0036     444040     -> call() file:line"
    // Group 1: indent spaces before "->", group 2: call with args
    private const CALL_RE = '/^\s+[\d.]+\s+\d+([ ]*)->\s+(.+?)\s+\//';

    // Only TraceableEventDispatcher->dispatch — this is the outermost, has $eventName in args
    private const DISPATCH_RE = '/TraceableEventDispatcher->dispatch$/';
    private const EVENT_NAME_RE = '/\$eventName\s*=\s*\'([^\']+)\'/';
    private const EVENT_CLASS_RE = '/\$event\s*=\s*class\s+([\w\\\\]+)/';

    // Classes to skip when looking for real listener name after WrappedListener->__invoke
    private const LISTENER_NOISE = [
        'TraceableEventDispatcher', 'EventDispatcher', 'WrappedListener',
        'Stopwatch', 'StopwatchEvent', 'isPropagationStopped', 'Section',
    ];

    public function __construct(
        private readonly string $tracesDir,
        private readonly EntityManagerInterface $em,
    ) {}

    public function parse(TraceFile $traceFile, string $xtFilePath): void
    {
        $traceFile->setStatus('parsing')->setProgress(0);
        $this->em->flush();

        $dir = $this->tracesDir . '/' . $traceFile->getId();
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $totalLines = $this->countLines($xtFilePath);
        $sparseIndex = [];
        $skeleton = [];
        $seenSigs = [];

        // TOC state
        $toc = [];
        $mainDepth = null;
        // Stack of open dispatch blocks (nested dispatches inside listeners)
        // Each entry: ['event' => string, 'line_no' => int, 'depth' => int, 'listeners' => [...]]
        $dispatchStack = [];
        $pendingInvoke = null; // ['depth' => int]

        $requestInfo = $this->extractRequestInfo($xtFilePath);

        $fh = fopen($xtFilePath, 'rb');
        $lineNo = 0;
        $offset = 0;

        $nodes = [];
        $depthStack = [];

        while (($line = fgets($fh)) !== false) {
            $lineNo++;

            if ($lineNo % self::SPARSE_EVERY === 0) {
                $sparseIndex[(string)$lineNo] = $offset;
            }

            if (preg_match(self::CALL_RE, $line, $m)) {
                $depth = (int)(strlen($m[1]) / 2);
                $sig = $this->extractSignature($m[2]);

                // --- TOC: track {main} depth ---
                if ($sig === '{main}' && $mainDepth === null) {
                    $mainDepth = $depth;
                }

                // --- TOC: pop closed dispatch blocks from stack ---
                // When depth returns to <= a dispatch's own depth, that dispatch is done
                while (!empty($dispatchStack) && $depth <= $dispatchStack[count($dispatchStack)-1]['depth']
                    && !str_ends_with($sig, '->dispatch')) {
                    $closed = array_pop($dispatchStack);
                    $toc[] = $closed;
                    $pendingInvoke = null;
                }

                // --- TOC: detect TraceableEventDispatcher->dispatch (outermost, has $eventName) ---
                if (preg_match(self::DISPATCH_RE, $sig)) {
                    $eventName = null;
                    $eventClass = null; // full FQCN when available
                    if (preg_match(self::EVENT_NAME_RE, $line, $em2)) {
                        $eventName = $em2[1];
                    } elseif (preg_match(self::EVENT_CLASS_RE, $line, $em2)) {
                        $eventClass = $em2[1]; // full FQCN
                        $parts = explode('\\', $em2[1]);
                        $eventName = end($parts);
                    }
                    if ($eventName !== null) {
                        // Check if top of stack is same event (Traceable wraps EventDispatcher, same event)
                        $top = !empty($dispatchStack) ? $dispatchStack[count($dispatchStack)-1] : null;
                        $shortName = str_contains($eventName, '\\')
                            ? substr($eventName, strrpos($eventName, '\\') + 1)
                            : $eventName;
                        $topShort = $top ? (str_contains($top['event'], '\\')
                            ? substr($top['event'], strrpos($top['event'], '\\') + 1)
                            : $top['event']) : null;

                        if ($top && $topShort === $shortName) {
                            // Same event at deeper depth — update depth, keep listeners
                            $dispatchStack[count($dispatchStack)-1]['depth'] = $depth;
                            if (!str_contains($eventName, '\\')) {
                                $dispatchStack[count($dispatchStack)-1]['event'] = $eventName;
                            }
                            if ($eventClass && !isset($dispatchStack[count($dispatchStack)-1]['event_class'])) {
                                $dispatchStack[count($dispatchStack)-1]['event_class'] = $eventClass;
                            }
                        } else {
                            // New event — push onto stack
                            $entry = [
                                'type'      => 'event',
                                'event'     => $eventName,
                                'line_no'   => $lineNo,
                                'depth'     => $depth,
                                'listeners' => [],
                            ];
                            if ($eventClass) $entry['event_class'] = $eventClass;
                            $dispatchStack[] = $entry;
                        }
                        $pendingInvoke = null;
                    }
                }

                // --- TOC: detect WrappedListener->__invoke inside current dispatch ---
                if (!empty($dispatchStack) && str_ends_with($sig, 'WrappedListener->__invoke')) {
                    $pendingInvoke = ['depth' => $depth];
                }

                // --- TOC: real listener = first non-noise call at invoke_depth+1 ---
                if ($pendingInvoke !== null && $depth === $pendingInvoke['depth'] + 1) {
                    $isNoise = false;
                    foreach (self::LISTENER_NOISE as $noise) {
                        if (str_contains($sig, $noise)) { $isNoise = true; break; }
                    }
                    if (!$isNoise && str_contains($sig, '\\') && !empty($dispatchStack)) {
                        $dispatchStack[count($dispatchStack)-1]['listeners'][] = [
                            'sig'     => $sig,
                            'line_no' => $lineNo,
                            'depth'   => $depth,
                        ];
                        $pendingInvoke = null;
                    }
                }

                // --- skeleton building (unchanged) ---
                if (!isset($seenSigs[$sig])) {
                    $seenSigs[$sig] = true;
                    $idx = count($nodes);
                    $nodes[] = ['sig' => $sig, 'depth' => $depth, 'first_line' => $lineNo, 'children' => []];

                    while (count($depthStack) > 0 && $depthStack[count($depthStack) - 1]['depth'] >= $depth) {
                        array_pop($depthStack);
                    }

                    if (count($depthStack) > 0) {
                        $parentIdx = $depthStack[count($depthStack) - 1]['idx'];
                        $nodes[$parentIdx]['children'][] = $idx;
                    } else {
                        $skeleton[] = $idx;
                    }

                    $depthStack[] = ['depth' => $depth, 'idx' => $idx];
                }
            }

            $offset = ftell($fh);

            if ($lineNo % 50000 === 0 && $totalLines > 0) {
                $progress = (int)(($lineNo / $totalLines) * 100);
                $traceFile->setProgress(min($progress, 99));
                $this->em->flush();
            }
        }

        fclose($fh);

        // Flush remaining open dispatch blocks (outermost last)
        while (!empty($dispatchStack)) {
            $toc[] = array_pop($dispatchStack);
        }

        $sparseIndex = ['0' => 0] + $sparseIndex;
        ksort($sparseIndex, SORT_NUMERIC);

        file_put_contents($dir . '/line_index.json', json_encode($sparseIndex));
        file_put_contents($dir . '/skeleton.json', json_encode(
            ['nodes' => $nodes, 'roots' => $skeleton],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        ));
        file_put_contents($dir . '/toc.json', json_encode($toc, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        $responseInfo = $this->extractResponseInfo($xtFilePath);
        file_put_contents($dir . '/meta.json', json_encode(
            ['total_lines' => $lineNo, 'request' => $requestInfo, 'response' => $responseInfo],
            JSON_UNESCAPED_UNICODE
        ));

        $traceFile->setStatus('ready')->setProgress(100);
        $this->em->flush();
    }

    private function extractResponseInfo(string $xtFilePath): array
    {
        $info = ['status' => null, 'location' => null, 'cookies' => []];

        // Scan full file line by line for setCookie calls and RedirectResponse
        // These are real xdebug call lines, not VarDumper dumps
        $fh = fopen($xtFilePath, 'rb');
        $cookiesSeen = [];

        while (($line = fgets($fh)) !== false) {
            // ResponseHeaderBag->setCookie($cookie = class ...Cookie { protected $name = 'sio_u'; protected $value = '...' })
            if (str_contains($line, '->setCookie') && str_contains($line, 'Cookie')) {
                if (preg_match('/\$name\s*=\s*\'([^\']+)\'/', $line, $m)) {
                    $name = $m[1];
                    if (!isset($cookiesSeen[$name])) {
                        $cookiesSeen[$name] = true;
                        $cookie = ['name' => $name];
                        if (preg_match('/\$value\s*=\s*\'([^\']+)\'/', $line, $mv)) {
                            $val = $mv[1];
                            $cookie['value'] = strlen($val) > 40 ? substr($val, 0, 20) . '…' : $val;
                        }
                        $info['cookies'][] = $cookie;
                    }
                }
            }
            // RedirectResponse->__construct or targetUrl in object dump
            if ($info['location'] === null && str_contains($line, 'RedirectResponse')) {
                if (preg_match('/\$url\s*=\s*\'([^\']+)\'/', $line, $m)) {
                    $info['location'] = $m[1];
                } elseif (preg_match('/targetUrl\s*=\s*\'([^\']+)\'/', $line, $m)) {
                    $info['location'] = $m[1];
                }
            }
            // Response->setStatusCode or statusCode in dump
            if ($info['status'] === null && str_contains($line, 'setStatusCode')) {
                if (preg_match('/\$code\s*=\s*(\d{3})/', $line, $m)) {
                    $info['status'] = (int)$m[1];
                }
            }
        }

        fclose($fh);

        // Fallback: grab statusCode from VarDumper dump of final Response if setStatusCode not found
        if ($info['status'] === null) {
            $fh = fopen($xtFilePath, 'rb');
            fseek($fh, 0, SEEK_END);
            $size = ftell($fh);
            fseek($fh, max(0, $size - 524288));
            $tail = fread($fh, 524288);
            fclose($fh);
            if (preg_match_all('/statusCode\s*=\s*(\d{3})/', $tail, $matches)) {
                $info['status'] = (int)end($matches[1]);
            }
        }

        return $info;
    }

    private function extractSignature(string $call): string
    {
        $paren = strpos($call, '(');
        return $paren !== false ? trim(substr($call, 0, $paren)) : trim($call);
    }

    private function extractRequestInfo(string $xtFilePath): array
    {
        $info = [];
        $fh = fopen($xtFilePath, 'rb');

        // TRACE START [2026-05-30 20:34:36.703988]
        $firstLine = fgets($fh);
        if ($firstLine && preg_match('/TRACE START \[([^\]]+)\]/', $firstLine, $m)) {
            $info['started_at'] = $m[1];
        }

        // Scan first ~200 lines for the server array (appears in first closure call)
        $scanned = 0;
        while (($line = fgets($fh)) !== false && $scanned < 10000) {
            $scanned++;

            if (str_contains($line, 'REQUEST_METHOD') && str_contains($line, 'REQUEST_URI')) {
                // Extract individual fields via regex
                if (preg_match("/'REQUEST_METHOD'\s*=>\s*'([^']+)'/", $line, $m)) {
                    $info['method'] = $m[1];
                }
                if (preg_match("/'REQUEST_URI'\s*=>\s*'([^']+)'/", $line, $m)) {
                    $info['uri'] = $m[1];
                }
                if (preg_match("/'HTTP_HOST'\s*=>\s*'([^']+)'/", $line, $m)) {
                    $info['host'] = $m[1];
                }
                if (preg_match("/'QUERY_STRING'\s*=>\s*'([^']*)'/", $line, $m)) {
                    $info['query'] = $m[1];
                }
                if (preg_match("/'HTTP_COOKIE'\s*=>\s*'([^']+)'/", $line, $m)) {
                    // Parse cookies into key=>value pairs
                    $cookies = [];
                    foreach (explode(';', $m[1]) as $pair) {
                        [$k, $v] = array_map('trim', explode('=', trim($pair), 2)) + ['', ''];
                        if ($k !== '') $cookies[$k] = $v;
                    }
                    $info['cookies'] = $cookies;
                }
                if (preg_match("/'HTTP_USER_AGENT'\s*=>\s*'([^']+)'/", $line, $m)) {
                    $info['user_agent'] = $m[1];
                }
                if (preg_match("/'REMOTE_ADDR'\s*=>\s*'([^']+)'/", $line, $m)) {
                    $info['remote_addr'] = $m[1];
                }
                if (preg_match("/'REQUEST_TIME_FLOAT'\s*=>\s*([\d.]+)/", $line, $m)) {
                    $info['request_time'] = (float)$m[1];
                }
                if (preg_match("/'CONTENT_TYPE'\s*=>\s*'([^']+)'/", $line, $m)) {
                    $info['content_type'] = $m[1];
                }
                if (preg_match("/'HTTP_REFERER'\s*=>\s*'([^']+)'/", $line, $m)) {
                    $info['referer'] = $m[1];
                }
                break;
            }
        }

        fclose($fh);
        return $info;
    }

    private function countLines(string $path): int
    {
        $count = 0;
        $fh = fopen($path, 'rb');
        while (!feof($fh)) {
            $chunk = fread($fh, 65536);
            $count += substr_count($chunk, "\n");
        }
        fclose($fh);
        return $count;
    }
}
