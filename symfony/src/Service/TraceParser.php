<?php

namespace App\Service;

use App\Entity\TraceFile;
use Doctrine\ORM\EntityManagerInterface;

class TraceParser
{
    private const SPARSE_EVERY = 500;

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
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create directory: $dir");
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
        $lastShallowSig = null; // last sig seen at depth <= 18, used to label vote event caller

        // Per-block app_calls tree: for each open dispatchStack entry we maintain
        // a parallel app-call depth-stack so App\ calls form a tree of "what your code did".
        // $appCallsStacks[$stackIdx] = depth-stack of {sig, depth, line_no, children:[]}
        // The roots of each tree are stored in dispatchStack entry['app_calls'].
        $appCallsStacks = []; // indexed by dispatchStack index (rebuilt on push/pop)

        $requestInfo = $this->extractRequestInfo($xtFilePath);

        $fh = fopen($xtFilePath, 'rb');
        $lineNo = 0;
        $offset = 0;

        $nodes = [];
        $depthStack = [];

        // Tracks last app_call added per active block so return values can be attached.
        // Each entry: ['block_idx' => int, 'depth' => int, 'path' => [int, ...]] where path
        // is the child-index path into dispatchStack[$block_idx]['app_calls'] tree.
        // Keyed by block_idx (int).
        $lastAppCallPath = []; // block_idx => ['depth' => int, 'path' => [int,...]]

        while (($line = fgets($fh, 1048576)) !== false) {
            $lineNo++;

            if ($lineNo % self::SPARSE_EVERY === 0) {
                $sparseIndex[(string)$lineNo] = $offset;
            }

            // Handle return value lines: attach to last app_call node at matching depth
            if (preg_match(TraceRegex::ReturnLine->value, $line, $rm)) {
                $retDepth = (int)(strlen($rm[1]) / 2);
                $retVal = $this->simplifyValue(trim($rm[2]));
                if ($retVal !== null && !empty($dispatchStack)) {
                    $blockIdx = count($dispatchStack) - 1;
                    $info = $lastAppCallPath[$blockIdx] ?? null;
                    if ($info !== null && $info['depth'] === $retDepth) {
                        // Navigate to the node via path and set return
                        $ref = &$dispatchStack[$blockIdx]['app_calls'];
                        foreach ($info['path'] as $i => $idx) {
                            if ($i < count($info['path']) - 1) {
                                $ref = &$ref[$idx]['children'];
                            } else {
                                $ref[$idx]['return'] = $retVal;
                            }
                        }
                        unset($ref);
                        unset($lastAppCallPath[$blockIdx]);
                    }
                }
                $offset = ftell($fh);
                continue;
            }

            if (preg_match(TraceRegex::CallLine->value, $line, $m)) {
                $depth = (int)(strlen($m[1]) / 2);
                $sig = $this->extractSignature($m[2]);

                // Track which top-level security listener is executing, to label vote events.
                // Always overwrite when we see a new listener; reset on depth ≤ 11 (between kernel.request listeners).
                if ($depth <= 11) {
                    $lastShallowSig = null;
                } elseif (str_contains($sig, '\\')
                    && (str_contains($sig, 'TwoFactorAccessListener') || str_contains($sig, 'Firewall\\AccessListener'))
                ) {
                    $parts = explode('\\', $sig);
                    $lastShallowSig = end($parts);
                }

                // --- TOC: track {main} depth ---
                if ($sig === '{main}' && $mainDepth === null) {
                    $mainDepth = $depth;
                }

                // --- TOC: pop closed dispatch blocks from stack ---
                // When depth returns to <= a dispatch's own depth, that dispatch is done.
                // If a parent remains on the stack, the closed event is nested inside it → children[].
                // Only top-level events (nothing left on stack) go into $toc directly.
                while (!empty($dispatchStack) && $depth <= $dispatchStack[count($dispatchStack)-1]['depth']
                    && !str_ends_with($sig, '->dispatch')) {
                    array_pop($appCallsStacks); // drop the closed block's app-call depth-stack
                    $closed = array_pop($dispatchStack);
                    if ($closed['type'] === 'controller') {
                        // Wrap controller children in a synthetic toc entry so they don't appear
                        // as top-level events — only emit the wrapper when there are children.
                        // Always emit when there are app_calls even if no dispatch children.
                        if (!empty($closed['children']) || !empty($closed['app_calls'])) {
                            $wrapper = [
                                'type'      => 'controller_execution',
                                'event'     => $closed['event'],
                                'line_no'   => $closed['line_no'],
                                'depth'     => $closed['depth'],
                                'listeners' => [],
                                'children'  => $closed['children'],
                                'app_calls' => $closed['app_calls'] ?? [],
                            ];
                            if (!empty($dispatchStack)) {
                                $dispatchStack[count($dispatchStack)-1]['children'][] = $wrapper;
                            } else {
                                $toc[] = $wrapper;
                            }
                        }
                    } elseif (!empty($dispatchStack)) {
                        $dispatchStack[count($dispatchStack)-1]['children'][] = $closed;
                    } else {
                        $toc[] = $closed;
                    }
                    $pendingInvoke = null;
                }

                // --- TOC: detect controller call — push as container for nested dispatches ---
                // The first App\Controller action call at kernel-event depth is the controller execution.
                // We push it onto the stack so any dispatches inside it (e.g. security votes) become
                // its children. When it closes (depth returns to kernel level for kernel.response),
                // its children are promoted into toc as a synthetic "controller_execution" entry.
                $topType = !empty($dispatchStack) ? $dispatchStack[count($dispatchStack)-1]['type'] : null;
                if ($topType !== 'controller' && empty($dispatchStack) && $mainDepth !== null
                    && str_contains($sig, 'App\\Controller\\')
                    && str_contains($sig, '->')
                    && !str_contains($sig, '__construct')
                    && !str_contains($sig, 'inject')
                    && !str_contains($sig, 'set')
                ) {
                    $dispatchStack[] = [
                        'type'      => 'controller',
                        'event'     => $sig,
                        'line_no'   => $lineNo,
                        'depth'     => $depth,
                        'listeners' => [],
                        'children'  => [],
                        'app_calls' => [],
                    ];
                    $appCallsStacks[] = []; // empty depth-stack for this block
                }

                // --- TOC: detect TraceableEventDispatcher->dispatch (outermost, has $eventName) ---
                if (preg_match(TraceRegex::DispatchCall->value, $sig)) {
                    $eventName = null;
                    $eventClass = null; // full FQCN when available
                    if (preg_match(TraceRegex::EventName->value, $line, $em2)) {
                        $eventName = $em2[1];
                    } elseif (preg_match(TraceRegex::EventClass->value, $line, $em2)) {
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
                        if ($top) {
                            $topShort = str_contains($top['event'], '\\')
                                ? substr($top['event'], strrpos($top['event'], '\\') + 1)
                                : $top['event'];
                        } else {
                            $topShort = null;
                        }

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
                                'children'  => [],
                                'app_calls' => [],
                            ];
                            if ($eventClass) $entry['event_class'] = $eventClass;
                            // For vote events: record which security layer triggered this decide()
                            if ($eventName === 'debug.security.authorization.vote' && $lastShallowSig !== null) {
                                $entry['caller'] = $lastShallowSig;
                            }
                            $dispatchStack[] = $entry;
                            $appCallsStacks[] = []; // empty depth-stack for this block
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

                // --- TOC: enrich last listener with voter_class from addVoterVote ---
                if (!empty($dispatchStack) && str_ends_with($sig, 'TraceableAccessDecisionManager->addVoterVote')) {
                    $top = &$dispatchStack[count($dispatchStack)-1];
                    if (!empty($top['listeners'])) {
                        $last = &$top['listeners'][count($top['listeners'])-1];
                        if (!isset($last['voter_class']) && preg_match(TraceRegex::VoterClass->value, $line, $vm)) {
                            $parts = explode('\\', $vm[1]);
                            $last['voter_class'] = end($parts);
                        }
                        if (!isset($last['vote_attrs']) && preg_match(TraceRegex::VoterAttrs->value, $line, $am)) {
                            preg_match_all("/'([^']+)'/", $am[1], $strings);
                            if ($strings[1]) $last['vote_attrs'] = $strings[1];
                        }
                        if (!isset($last['vote_result']) && preg_match(TraceRegex::VoterResult->value, $line, $rm)) {
                            $last['vote_result'] = (int)$rm[1]; // -1=DENIED, 0=ABSTAIN, 1=GRANTED
                        }
                        unset($last);
                    }
                    unset($top);
                }

                // --- TOC: app_calls tree — track App\ calls inside the active dispatch/controller block ---
                if (!empty($dispatchStack) && str_contains($sig, 'App\\') && str_contains($sig, '->')) {
                    $blockIdx = count($dispatchStack) - 1;
                    $acStack  = &$appCallsStacks[$blockIdx];

                    // Pop depth-stack entries that are at same or deeper depth
                    while (!empty($acStack) && $acStack[count($acStack)-1]['depth'] >= $depth) {
                        array_pop($acStack);
                    }

                    $rawFile = $this->extractFile($m[2]);
                    $node = [
                        'sig'      => $sig,
                        'depth'    => $depth,
                        'line_no'  => $lineNo,
                        'file'     => $rawFile ? $this->shortFile($rawFile) : null,
                        'file_abs' => $rawFile,
                        'args'     => $this->extractArgs($m[2]),
                        'return'   => null,
                        'children' => [],
                    ];

                    if (!empty($acStack)) {
                        $parent = &$acStack[count($acStack)-1];
                        $parent['children'][] = $node;
                        $newIdx = count($parent['children']) - 1;
                        $acStack[] = &$parent['children'][$newIdx];
                        $newPath = $this->buildAppCallPath($dispatchStack[$blockIdx]['app_calls'], $lineNo);
                        $lastAppCallPath[$blockIdx] = ['depth' => $depth, 'path' => $newPath];
                        unset($parent);
                    } else {
                        $dispatchStack[$blockIdx]['app_calls'][] = $node;
                        $rootIdx = count($dispatchStack[$blockIdx]['app_calls']) - 1;
                        $acStack[] = &$dispatchStack[$blockIdx]['app_calls'][$rootIdx];
                        $lastAppCallPath[$blockIdx] = ['depth' => $depth, 'path' => [$rootIdx]];
                    }
                    unset($acStack);
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
            array_pop($appCallsStacks);
            $closed = array_pop($dispatchStack);
            if ($closed['type'] === 'controller') {
                if (!empty($closed['children']) || !empty($closed['app_calls'])) {
                    $wrapper = [
                        'type'      => 'controller_execution',
                        'event'     => $closed['event'],
                        'line_no'   => $closed['line_no'],
                        'depth'     => $closed['depth'],
                        'listeners' => [],
                        'children'  => $closed['children'],
                        'app_calls' => $closed['app_calls'] ?? [],
                    ];
                    if (!empty($dispatchStack)) {
                        $dispatchStack[count($dispatchStack)-1]['children'][] = $wrapper;
                    } else {
                        $toc[] = $wrapper;
                    }
                }
            } else {
                $toc[] = $closed;
            }
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

        while (($line = fgets($fh, 1048576)) !== false) {
            // ResponseHeaderBag->setCookie($cookie = class ...Cookie { protected $name = 'sio_u'; protected $value = '...' })
            if (str_contains($line, '->setCookie') && str_contains($line, 'Cookie')) {
                if (preg_match(TraceRegex::CookieName->value, $line, $m)) {
                    $name = $m[1];
                    if (!isset($cookiesSeen[$name])) {
                        $cookiesSeen[$name] = true;
                        $cookie = ['name' => $name];
                        if (preg_match(TraceRegex::CookieValue->value, $line, $mv)) {
                            $val = $mv[1];
                            $cookie['value'] = strlen($val) > 40 ? substr($val, 0, 20) . '…' : $val;
                        }
                        $info['cookies'][] = $cookie;
                    }
                }
            }
            // RedirectResponse->__construct or targetUrl in object dump
            if ($info['location'] === null && str_contains($line, 'RedirectResponse')) {
                if (preg_match(TraceRegex::RedirectUrl->value, $line, $m)) {
                    $info['location'] = $m[1];
                } elseif (preg_match(TraceRegex::RedirectTargetUrl->value, $line, $m)) {
                    $info['location'] = $m[1];
                }
            }
            // Response->setStatusCode or statusCode in dump
            if ($info['status'] === null && str_contains($line, 'setStatusCode')) {
                if (preg_match(TraceRegex::StatusCode->value, $line, $m)) {
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
            if (preg_match_all(TraceRegex::StatusCodeDump->value, $tail, $matches)) {
                $info['status'] = (int)end($matches[1]);
            }
        }

        return $info;
    }

    /**
     * Finds the path (array of child indices) to the node with $lineNo inside $appCalls tree.
     * Returns [] if not found.
     */
    private function buildAppCallPath(array $appCalls, int $lineNo): array
    {
        foreach ($appCalls as $idx => $node) {
            if ($node['line_no'] === $lineNo) return [$idx];
            if (!empty($node['children'])) {
                $sub = $this->buildAppCallPath($node['children'], $lineNo);
                if ($sub !== []) return array_merge([$idx], $sub);
            }
        }
        return [];
    }

    private function extractFile(string $raw): ?string
    {
        $pos = strrpos($raw, ' /');
        if ($pos === false) return null;
        $candidate = substr($raw, $pos + 1);
        if (preg_match(TraceRegex::FilePathSuffix->value, $candidate)) return $candidate;
        return null;
    }

    private function shortFile(string $fileColon): string
    {
        if (preg_match(TraceRegex::ShortFilePath->value, $fileColon, $m)) {
            $parts = explode('/', $m[2]);
            return implode('/', array_slice($parts, max(0, count($parts) - 3)));
        }
        return basename($fileColon);
    }

    private function extractArgs(string $call): array
    {
        $paren = strpos($call, '(');
        if ($paren === false) return [];
        $argsStr = substr($call, $paren + 1);
        $argsStr = preg_replace(TraceRegex::TrailingParen->value, '', $argsStr);
        if (trim($argsStr) === '' || $argsStr === '???') return [];

        $args = [];
        $depth = 0;
        $current = '';
        for ($i = 0; $i < strlen($argsStr); $i++) {
            $ch = $argsStr[$i];
            if ($ch === '{' || $ch === '[' || $ch === '(') $depth++;
            elseif ($ch === '}' || $ch === ']' || $ch === ')') $depth--;
            elseif ($ch === ',' && $depth === 0) {
                $args[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $ch;
        }
        if (trim($current) !== '') $args[] = trim($current);

        $result = [];
        foreach ($args as $arg) {
            if (preg_match(TraceRegex::ArgAssignment->value, $arg, $am)) {
                $val = $this->simplifyValue(trim($am[2]));
                if ($val !== null) {
                    $result[] = '$' . $am[1] . ' = ' . $val;
                }
            }
        }
        return $result;
    }

    private function simplifyValue(string $val): ?string
    {
        if (preg_match(TraceRegex::StringLiteral->value, $val, $m)) {
            $s = $m[1];
            if (strlen($s) > 40 && preg_match(TraceRegex::JwtToken->value, $s)) return "'<JWT>'";
            if (strlen($s) > 60) return "'" . substr($s, 0, 57) . "...'";
            return "'" . $s . "'";
        }
        if (preg_match(TraceRegex::ScalarLiteral->value, $val)) return $val;
        if (preg_match(TraceRegex::CookieObjectClass->value, $val)
            && preg_match(TraceRegex::CookieName->value, $val, $m)) {
            return "Cookie('" . $m[1] . "')";
        }
        if (preg_match(TraceRegex::ClassDump->value, $val, $m)) {
            $parts = explode('\\', $m[1]);
            return end($parts) . ' {…}';
        }
        if (preg_match(TraceRegex::EnumDump->value, $val, $m)) {
            $parts = explode('\\', $m[1]);
            return end($parts) . '::' . $m[2];
        }
        if (str_starts_with($val, '[') || str_starts_with($val, 'array(')) return '[…]';
        return null;
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
        $firstLine = fgets($fh, 1048576);
        if ($firstLine && preg_match(TraceRegex::TraceStart->value, $firstLine, $m)) {
            $info['started_at'] = $m[1];
        }

        // Scan first ~200 lines for the server array (appears in first closure call)
        $scanned = 0;
        while (($line = fgets($fh, 1048576)) !== false && $scanned < 10000) {
            $scanned++;

            if (str_contains($line, 'REQUEST_METHOD') && str_contains($line, 'REQUEST_URI')) {
                // Extract individual fields via regex
                if (preg_match(TraceRegex::ServerRequestMethod->value, $line, $m)) {
                    $info['method'] = $m[1];
                }
                if (preg_match(TraceRegex::ServerRequestUri->value, $line, $m)) {
                    $info['uri'] = $m[1];
                }
                if (preg_match(TraceRegex::ServerHttpHost->value, $line, $m)) {
                    $info['host'] = $m[1];
                }
                if (preg_match(TraceRegex::ServerQueryString->value, $line, $m)) {
                    $info['query'] = $m[1];
                }
                if (preg_match(TraceRegex::ServerHttpCookie->value, $line, $m)) {
                    // Parse cookies into key=>value pairs
                    $cookies = [];
                    foreach (explode(';', $m[1]) as $pair) {
                        [$k, $v] = array_map('trim', explode('=', trim($pair), 2)) + ['', ''];
                        if ($k !== '') $cookies[$k] = $v;
                    }
                    $info['cookies'] = $cookies;
                }
                if (preg_match(TraceRegex::ServerUserAgent->value, $line, $m)) {
                    $info['user_agent'] = $m[1];
                }
                if (preg_match(TraceRegex::ServerRemoteAddr->value, $line, $m)) {
                    $info['remote_addr'] = $m[1];
                }
                if (preg_match(TraceRegex::ServerRequestTimeFloat->value, $line, $m)) {
                    $info['request_time'] = (float)$m[1];
                }
                if (preg_match(TraceRegex::ServerContentType->value, $line, $m)) {
                    $info['content_type'] = $m[1];
                }
                if (preg_match(TraceRegex::ServerReferer->value, $line, $m)) {
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
