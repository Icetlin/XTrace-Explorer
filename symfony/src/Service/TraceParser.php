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

        $fileSize = filesize($xtFilePath) ?: 1;
        $progressFile = sys_get_temp_dir() . '/parse-progress-' . $traceFile->getId() . '.txt';
        $cancelFile   = sys_get_temp_dir() . '/parse-cancel-'   . $traceFile->getId() . '.txt';
        @unlink($cancelFile);
        $sparseIndex = [];
        $skeleton = [];
        $seenSigs = [];
        $tokenIndex = []; // [{line_no, class|null}, ...] — setToken() transitions, for fast inferTokenType lookups

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
        // Parallel to $appCallsStacks: for each open block, a stack of child-index paths
        // mirroring $appCallsStacks — top entry is the path to the current node in
        // dispatchStack[$blockIdx]['app_calls'], so the path to a freshly pushed node
        // is just "parent path + [newIdx]" (O(1), no tree search needed).
        $appCallsPathStacks = [];

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
                preg_match(TraceRegex::CallLineTimeMem->value, $line, $tm);
                $lineTime = isset($tm[1]) ? (float)$tm[1] : 0.0;
                $lineMem  = isset($tm[2]) ? (int)$tm[2] : 0;
                $depth = (int)(strlen($m[1]) / 2);
                $sig = $this->extractSignature($m[2]);

                // --- Token index: record setToken($token = ...) transitions ---
                if (str_ends_with($sig, '->setToken')) {
                    $tokenPrefix = '$token = ';
                    $tokenSuffix = ' {…}';
                    foreach ($this->extractArgs($m[2]) as $tokenArg) {
                        if (str_starts_with($tokenArg, $tokenPrefix) && str_ends_with($tokenArg, $tokenSuffix)) {
                            $tokenIndex[] = [
                                'line_no' => $lineNo,
                                'class'   => substr($tokenArg, strlen($tokenPrefix), -strlen($tokenSuffix)),
                            ];
                        } elseif ($tokenArg === $tokenPrefix . 'NULL') {
                            $tokenIndex[] = ['line_no' => $lineNo, 'class' => null];
                        }
                    }
                }

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
                    array_pop($appCallsPathStacks);
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
                    $appCallsPathStacks[] = []; // empty path-stack for this block
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
                            $appCallsPathStacks[] = []; // empty path-stack for this block
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
                        $rawFile = $this->extractFile($m[2]);
                        $dispatchStack[count($dispatchStack)-1]['listeners'][] = [
                            'sig'      => $sig,
                            'line_no'  => $lineNo,
                            'depth'    => $depth,
                            'file_abs' => $rawFile,
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
                    $acStack     = &$appCallsStacks[$blockIdx];
                    $acPathStack = &$appCallsPathStacks[$blockIdx];

                    // Pop depth-stack entries that are at same or deeper depth
                    while (!empty($acStack) && $acStack[count($acStack)-1]['depth'] >= $depth) {
                        $closing = &$acStack[count($acStack)-1];
                        if (isset($closing['time_start'])) {
                            $closing['duration_ms'] = (int)(($lineTime - $closing['time_start']) * 1000);
                            $closing['mem_delta_kb'] = (int)(($lineMem - $closing['mem_start']) / 1024);
                        }
                        unset($closing);
                        array_pop($acStack);
                        array_pop($acPathStack);
                    }

                    $rawFile = $this->extractFile($m[2]);
                    $node = [
                        'sig'        => $sig,
                        'depth'      => $depth,
                        'line_no'    => $lineNo,
                        'file'       => $rawFile ? $this->shortFile($rawFile) : null,
                        'file_abs'   => $rawFile,
                        'args'       => $this->extractArgs($m[2]),
                        'return'     => null,
                        'children'   => [],
                        'time_start' => $lineTime,
                        'mem_start'  => $lineMem,
                        'duration_ms'  => null,
                        'mem_delta_kb' => null,
                    ];

                    if (!empty($acStack)) {
                        $parent = &$acStack[count($acStack)-1];
                        $parent['children'][] = $node;
                        $newIdx = count($parent['children']) - 1;
                        $acStack[] = &$parent['children'][$newIdx];
                        $newPath = $acPathStack[count($acPathStack)-1];
                        $newPath[] = $newIdx;
                        $acPathStack[] = $newPath;
                        unset($parent);
                    } else {
                        $dispatchStack[$blockIdx]['app_calls'][] = $node;
                        $rootIdx = count($dispatchStack[$blockIdx]['app_calls']) - 1;
                        $acStack[] = &$dispatchStack[$blockIdx]['app_calls'][$rootIdx];
                        $newPath = [$rootIdx];
                        $acPathStack[] = $newPath;
                    }
                    $lastAppCallPath[$blockIdx] = ['depth' => $depth, 'path' => $newPath];
                    unset($acStack, $acPathStack);
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

            if ($lineNo % 1000 === 0) {
                if (file_exists($cancelFile)) {
                    @unlink($cancelFile);
                    fclose($fh);
                    return;
                }
                $progress = min(99, (int)($offset / $fileSize * 100));
                file_put_contents($progressFile, $progress);
            }
            if ($lineNo % 50000 === 0) {
                $traceFile->setProgress($progress ?? 0);
                $this->em->flush();
            }
        }

        fclose($fh);

        // Flush remaining open dispatch blocks (outermost last)
        while (!empty($dispatchStack)) {
            array_pop($appCallsStacks);
            array_pop($appCallsPathStacks);
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
        file_put_contents($dir . '/token_index.json', json_encode($tokenIndex));
        file_put_contents($dir . '/skeleton.json', json_encode(
            ['nodes' => $nodes, 'roots' => $skeleton],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        ));
        $this->inferReturnsInToc($toc);
        $this->cleanupAppCallNodes($toc);
        $tocJson = json_encode($toc, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        file_put_contents($dir . '/toc.json', $tocJson);

        // Per-event app_calls cache: split the 38MB+ toc.json into one file per event
        // so /api/app-calls/{id}/{eventIdx} doesn't have to decode the whole toc.
        $this->writeAppCallsCache($dir, $toc);

        // Launch SQL extraction in parallel with extractResponseInfo (both do a full file scan).
        $sqlOutFile = $dir . '/sql.json.tmp';
        $sqlProcess = $this->startSqlWorker($xtFilePath, $sqlOutFile, $dir . '/toc.json');

        $responseInfo = $this->extractResponseInfo($xtFilePath);

        $this->waitSqlWorker($sqlProcess, $sqlOutFile, $dir);
        file_put_contents($dir . '/meta.json', json_encode(
            ['total_lines' => $lineNo, 'request' => $requestInfo, 'response' => $responseInfo],
            JSON_UNESCAPED_UNICODE
        ));

        $traceFile->setStatus('ready')->setProgress(100);
        $this->em->flush();
        @unlink($progressFile);
    }

    private function startSqlWorker(string $xtFilePath, string $outFile, string $tocFile): mixed
    {
        $consoleBin = dirname(__DIR__, 2) . '/bin/console';
        $cmd = sprintf(
            'php %s trace:parse-sql %s %s %s',
            escapeshellarg($consoleBin),
            escapeshellarg($xtFilePath),
            escapeshellarg($outFile),
            escapeshellarg($tocFile),
        );
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) return null;
        fclose($pipes[0]);
        // Store pipes so we can close them later
        return ['proc' => $proc, 'pipes' => $pipes];
    }

    private function waitSqlWorker(mixed $worker, string $outFile, string $dir): void
    {
        if ($worker === null) return;
        ['proc' => $proc, 'pipes' => $pipes] = $worker;
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        if (file_exists($outFile)) {
            rename($outFile, $dir . '/sql.json');
        }
    }

    public function extractSqlQueriesPublic(string $xtFilePath, array $toc): array
    {
        return $this->extractSqlQueries($xtFilePath, $toc);
    }

    private function extractSqlQueries(string $xtFilePath, array $toc): array
    {
        // Build a flat sorted list of TOC event line ranges for lookup
        $tocRanges = [];
        $this->collectTocRanges($toc, $tocRanges);
        usort($tocRanges, fn($a, $b) => $a['line_no'] <=> $b['line_no']);

        $queries = [];
        $fh = fopen($xtFilePath, 'rb');
        $lineNo = 0;

        // Rolling window — fixed-size ring buffer, O(1) insert, no array_shift
        $windowSize = 800;
        $window     = array_fill(0, $windowSize, null);
        $windowHead = 0; // next write position
        $windowFull = false;

        while (($line = fgets($fh, 1048576)) !== false) {
            $lineNo++;

            // Track App\src calls in rolling window (cheap pre-filter first).
            // Only store entries that can actually be picked as a query caller —
            // infrastructure (ORM filters/queries, DBAL, enums) fills the window
            // without ever winning, flushing real business callers out too early.
            if (str_contains($line, '/src/') && str_contains($line, 'App\\')
                && preg_match('#^\s+[\d.]+\s+\d+([ ]*)->\s+(App\\\\[^\(]+)\(.*?(/var/www/[^\s]*src/[^\s]+:\d+)#', $line, $wm)
            ) {
                $wSig = trim($wm[2]);
                if (!str_contains($wSig, '\\ORM\\Filter\\')
                    && !str_contains($wSig, '\\ORM\\Query\\')
                    && !str_contains($wSig, '\\DBAL\\')
                    && !str_ends_with($wSig, '->addFilterConstraint')
                    && !str_contains($wSig, '\\Enum\\')
                    && !str_contains($wSig, '::from')
                ) {
                    $wDepth = (int)(strlen($wm[1]) / 2);
                    $wFile  = preg_replace('#.*/src/#', 'src/', $wm[3]);
                    $window[$windowHead] = [$lineNo, $wDepth, $wSig, $wFile];
                    $windowHead = ($windowHead + 1) % $windowSize;
                    if ($windowHead === 0) $windowFull = true;
                }
            }

            // Fast pre-filter for executeQuery lines
            if (!str_contains($line, 'executeQuery')) continue;
            if (!preg_match('/^\s+[\d.]+\s+(\d+)([ ]*)->\s+(?:[\w\\\\]+\\\\)*Connection->executeQuery\((.+)/', $line, $m)) continue;

            $depth = (int)(strlen($m[2]) / 2);
            $args  = $m[3];

            $time = 0.0;
            if (preg_match('/^\s+([\d.]+)/', $line, $tm)) $time = (float)$tm[1];

            // Extract SQL string (may be xdebug-truncated)
            $sql = null;
            if (preg_match("/\\\$sql\s*=\s*'((?:[^'\\\\]|\\\\.)*)('?\.\.\.)?/s", $args, $sm)) {
                $sql = $sm[1];
                if (!empty($sm[2])) $sql .= '...';
            }

            // Extract params
            $params = [];
            if (preg_match('/\$params\s*=\s*\[([^\]]*)\]/', $args, $pm)) {
                preg_match_all("/'([^']*)'/", $pm[1], $strings);
                preg_match_all('/\b(\d+)\b/', $pm[1], $ints);
                $params = array_merge($strings[1], $ints[1]);
            } elseif (preg_match('/\$params\s*=\s*\[…\]/', $args)) {
                $params = ['…'];
            }

            // TOC label
            $tocLabel = null;
            foreach ($tocRanges as $range) {
                if ($lineNo >= $range['line_no'] && $lineNo <= $range['end_line']) {
                    $tocLabel = $range['label'];
                    break;
                }
            }

            $caller = $this->findQueryCaller($window, $windowHead, $windowFull, $windowSize, $depth);

            $queries[] = [
                'n'           => count($queries) + 1,
                'line_no'     => $lineNo,
                'time'        => $time,
                'duration_ms' => null,
                'depth'       => $depth,
                'sql'         => $sql,
                'params'      => $params,
                'toc'         => $tocLabel,
                'caller'      => $caller,
            ];
        }

        fclose($fh);

        // Approximate query duration from time between consecutive calls.
        // Traces without collect_return have no >=> lines, so inter-query delta
        // is the next best signal (dominated by DB time for tight N+1 loops).
        $count = count($queries);
        for ($i = 0; $i < $count - 1; $i++) {
            $delta = $queries[$i + 1]['time'] - $queries[$i]['time'];
            if ($delta >= 0 && $delta < 10.0) { // sanity cap at 10 s
                $queries[$i]['duration_ms'] = round($delta * 1000, 1);
            }
        }

        return $queries;
    }

    private function collectTocRanges(array $toc, array &$ranges, int $parentEnd = PHP_INT_MAX): void
    {
        $count = count($toc);
        foreach ($toc as $i => $entry) {
            $start = $entry['line_no'];
            // End = start of next sibling, or parent's end
            $end = ($i + 1 < $count) ? ($toc[$i + 1]['line_no'] - 1) : $parentEnd;

            $label = $entry['event'] ?? $entry['sig'] ?? '?';
            $ranges[] = ['line_no' => $start, 'end_line' => $end, 'label' => $label];

            if (!empty($entry['children'])) {
                $this->collectTocRanges($entry['children'], $ranges, $end);
            }
        }
    }

    private function findQueryCaller(array $window, int $head, bool $full, int $size, int $executeDepth): ?array
    {
        $best = null;
        $bestScore = -1;

        // Iterate ring buffer from newest to oldest
        $count = $full ? $size : $head;
        for ($j = 0; $j < $count; $j++) {
            $i = ($head - 1 - $j + $size) % $size;
            $slot = $window[$i];
            if ($slot === null) continue;
            [$wLine, $wDepth, $wSig, $wFile] = $slot;

            // For lazy-loaded queries the App\ caller can be many levels shallower
            // (e.g. depth 5 vs executeQuery depth 25). Don't break on depth —
            // the window size already provides temporal locality.

            // Skip Doctrine ORM infrastructure and non-initiator app code.
            if (str_contains($wSig, '\\ORM\\Filter\\')
                || str_contains($wSig, '\\ORM\\Query\\')
                || str_contains($wSig, '\\DBAL\\')
                || str_ends_with($wSig, '->addFilterConstraint')
                || str_contains($wSig, '\\Enum\\')          // App enum helpers are never query initiators
                || str_contains($wSig, '::from')            // Enum::from() calls
            ) {
                continue;
            }

            // Score: Repository > Service > Controller > Entity, prefer shallower depth
            $score = 0;
            if (str_contains($wSig, 'Repository'))  $score = 40;
            elseif (str_contains($wSig, 'Service'))  $score = 25;
            elseif (str_contains($wSig, 'Controller')) $score = 15;
            elseif (str_contains($wSig, '\\Entity\\')) $score = 8;
            else $score = 5;

            // Prefer methods that clearly initiate a query
            $method = substr($wSig, strrpos($wSig, '->') + 2);
            if (preg_match('/^(find|get|fetch|load|create|build|select|count|paginate)/i', $method)) {
                $score += 5;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = ['sig' => $wSig, 'file' => $wFile, 'line_no' => $wLine, 'depth' => $wDepth];
            }
        }

        return $best;
    }

    /**
     * Write per-event app_calls JSON files for fast lazy loading.
     * Layout: $dir/app_calls/{ei}.json where ei is the top-level index into toc.
     * The frontend's /api/app-calls/{fileId}/{ei} uses the same top-level index.
     *
     * Only top-level events are cached — nested events (accessed via NestedEventList)
     * are small enough that the toc.json fallback path is fast for them. Caching
     * nested events with the same flat counter would mismatch the front-end's ei.
     */
    private function writeAppCallsCache(string $dir, array $toc): void
    {
        $cacheDir = $dir . '/app_calls';
        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
            return;
        }
        foreach ($toc as $ei => $entry) {
            if (empty($entry['app_calls'])) continue;
            file_put_contents(
                $cacheDir . '/' . $ei . '.json',
                json_encode(
                    $entry['app_calls'],
                    JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
                )
            );
        }
    }

    private function cleanupAppCallNodes(array &$toc): void
    {
        foreach ($toc as &$entry) {
            if (isset($entry['app_calls'])) {
                $this->cleanupAppCallsTree($entry['app_calls']);
            }
            if (!empty($entry['children'])) {
                $this->cleanupAppCallNodes($entry['children']);
            }
        }
        unset($entry);
    }

    private function cleanupAppCallsTree(array &$calls): void
    {
        foreach ($calls as &$node) {
            unset($node['time_start'], $node['mem_start'], $node['depth']);
            if (!empty($node['children'])) {
                $this->cleanupAppCallsTree($node['children']);
            }
        }
        unset($node);
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
     * For each list of sibling app_calls, infer the return value of call[i] from
     * the first argument of call[i+1] that matches the pattern "$varName = <value>".
     * This works because xdebug traces don't include >=> return lines by default,
     * but the return value of a call is passed as an argument to the next sibling.
     */
    private function inferReturnsInAppCalls(array &$calls): void
    {
        for ($i = 0; $i < count($calls); $i++) {
            if ($calls[$i]['return'] === null && isset($calls[$i + 1])) {
                $nextArgs = $calls[$i + 1]['args'] ?? [];
                if (!empty($nextArgs)) {
                    // First arg: "$varName = SomeValue" or just "SomeValue"
                    $raw = $nextArgs[0];
                    $val = preg_replace('/^\$\w+\s*=\s*/', '', $raw);
                    // Only use scalar values — objects ({…}) and arrays ([…]) are almost always
                    // just the same input parameter passed along, not the actual return value.
                    if ($val !== '' && $val !== 'null' && $val !== 'NULL'
                        && !str_contains($val, '{…}') && !str_contains($val, '[…]')
                    ) {
                        $calls[$i]['return'] = $val;
                    }
                }
            }
            if (!empty($calls[$i]['children'])) {
                $this->inferReturnsInAppCalls($calls[$i]['children']);
            }
        }
    }

    private function inferReturnsInToc(array &$toc): void
    {
        foreach ($toc as &$entry) {
            if (!empty($entry['app_calls'])) {
                $this->inferReturnsInAppCalls($entry['app_calls']);
            }
            if (!empty($entry['children'])) {
                $this->inferReturnsInToc($entry['children']);
            }
        }
        unset($entry);
    }

    private function extractFile(string $raw): ?string
    {
        $pos = strrpos($raw, ' /');
        if ($pos === false) return null;
        $candidate = rtrim(substr($raw, $pos + 1));
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

}
