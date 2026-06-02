<?php

namespace App\Service;

class TraceIndex
{
    // Format: "    0.0036     444040     -> call() /file:line"
    // Group 1: indent spaces, group 2: everything after "-> " (call + args + file suffix)
    // File is parsed separately via strrchr to avoid catastrophic backtracking on 20KB+ lines.
    private const CALL_RE = '/^\s+[\d.]+\s+\d+([ ]*)->\s+(.+)$/';

    public function __construct(private readonly string $tracesDir) {}

    /**
     * Scans the trace file for all occurrences of any favourite pattern.
     * Returns a map: event_idx → listener_idx → [{ pattern, line_no }]
     *
     * Algorithm:
     *   1. Single pass through the file, checking each line against all patterns.
     *   2. For each hit, binary-search the flat listener list (sorted by line_no) to find
     *      which listener owns that line, then look up its event index.
     */
    public function scanFavourites(int $fileId, string $xtPath, array $patterns): array
    {
        if (!$patterns) return [];

        $tocPath = $this->tracesDir . '/' . $fileId . '/toc.json';
        if (!file_exists($tocPath)) return [];
        $toc = json_decode(file_get_contents($tocPath), true);

        // Build flat sorted list of listeners with their ranges:
        // [{ event_idx, listener_idx, start_line, end_line }]
        // end_line = next listener start - 1, or next event start - 1, or PHP_INT_MAX
        // Listeners whose subtree is noise for favourite scanning (profiler/debug infra).
        $scanNoiseListeners = ['ProfilerListener'];

        $flat = [];
        foreach ($toc as $ei => $event) {
            $listeners = $event['listeners'] ?? [];
            foreach ($listeners as $li => $listener) {
                $sig = $listener['sig'] ?? '';
                $noise = false;
                foreach ($scanNoiseListeners as $n) {
                    if (str_contains($sig, $n)) { $noise = true; break; }
                }
                $flat[] = [
                    'ei'         => $ei,
                    'li'         => $li,
                    'start'      => $listener['line_no'],
                    'depth'      => $listener['depth'] ?? 0,
                    'end'        => PHP_INT_MAX,
                    'noise'      => $noise,
                    'voter_class' => $listener['voter_class'] ?? null,
                ];
            }
        }
        usort($flat, fn($a, $b) => $a['start'] <=> $b['start']);

        // Compute real end_line for each listener by scanning the file once.
        // end_line = last line where depth >= listener_depth before depth drops below it.
        // We do this in a single forward pass using a pointer per flat entry.
        {
            $indexPath2 = $this->tracesDir . '/' . $fileId . '/line_index.json';
            $index2 = json_decode(file_get_contents($indexPath2), true);
            $firstStart = $flat ? $flat[0]['start'] : PHP_INT_MAX;
            $startOffset2 = 0; $startLine2 = 0;
            foreach ($index2 as $il => $off) {
                $il = (int)$il;
                if ($il <= $firstStart) { $startOffset2 = $off; $startLine2 = $il; }
            }

            $fh2 = fopen($xtPath, 'rb');
            fseek($fh2, $startOffset2);
            $ln2 = $startLine2;
            // active: stack of flat indices whose scope is still open
            $active = [];
            $fi = 0; $fCount = count($flat);

            while (($l2 = fgets($fh2, 1048576)) !== false) {
                $ln2++;
                if (!preg_match(self::CALL_RE, $l2, $cm2)) continue;
                $d2 = (int)(strlen($cm2[1]) / 2);

                // Activate listeners that start at this line
                while ($fi < $fCount && $flat[$fi]['start'] === $ln2) {
                    $active[] = $fi;
                    $fi++;
                }
                // Skip lines before first listener
                if (!$active && $fi < $fCount && $ln2 < $flat[$fi]['start']) continue;

                // Close listeners whose depth dropped below their own depth
                foreach ($active as $k => $idx) {
                    if ($d2 < $flat[$idx]['depth']) {
                        $flat[$idx]['end'] = $ln2 - 1;
                        unset($active[$k]);
                    }
                }
                $active = array_values($active);

                // Activate any new listeners at this line (edge case: same line)
                while ($fi < $fCount && $flat[$fi]['start'] <= $ln2) {
                    if ($flat[$fi]['start'] === $ln2) $active[] = $fi;
                    $fi++;
                }

                // Once all listeners are activated and none are active, stop
                if ($fi >= $fCount && !$active && $ln2 > $flat[$fCount - 1]['start']) break;
            }
            fclose($fh2);
        }

        // Cap each listener's end at the start of the next listener (minus 1).
        // The depth-drop heuristic above may leave end=PHP_INT_MAX when adjacent
        // listeners share the same call-depth boundary, causing cross-listener hits.
        for ($i = 0; $i < count($flat) - 1; $i++) {
            if ($flat[$i]['end'] >= $flat[$i + 1]['start']) {
                $flat[$i]['end'] = $flat[$i + 1]['start'] - 1;
            }
        }

        $startLines = array_column($flat, 'start');

        // Results map: ei → li → hits[]
        $result = [];

        $fh = fopen($xtPath, 'rb');
        $lineNo = 0;

        while (($line = fgets($fh, 1048576)) !== false) {
            $lineNo++;

            // Quick pre-filter: skip if no pattern appears anywhere in the line
            $anyMatch = false;
            foreach ($patterns as $p) {
                if ($p['pattern'] !== '' && str_contains($line, $p['pattern'])) {
                    $anyMatch = true;
                    break;
                }
            }
            if (!$anyMatch) continue;

            // Parse the call line; match only against sig + simplified args (not raw object dumps)
            if (!preg_match(self::CALL_RE, $line, $cm)) continue;

            $hitDepth = (int)(strlen($cm[1]) / 2);

            $sig = $this->extractSig($cm[2]);
            $searchable = null; // computed lazily for non-class patterns

            $matched = [];
            foreach ($patterns as $p) {
                if ($p['pattern'] === '') continue;
                // PascalCase patterns are class names — match only in sig to avoid false
                // positives where Profiler/DI enumerates class names as string identifiers.
                if (preg_match('/^[A-Z][A-Za-z0-9]+$/', $p['pattern'])) {
                    if (str_contains($sig, $p['pattern'])) $matched[] = $p;
                } else {
                    if ($searchable === null) {
                        $args = $this->extractArgs($cm[2]);
                        $searchable = $sig . ' ' . implode(' ', $args);
                    }
                    if (str_contains($searchable, $p['pattern'])) $matched[] = $p;
                }
            }
            if (!$matched) continue;

            // Binary search: find last flat entry with start <= lineNo, then walk back to find one whose end >= lineNo
            $lo = 0; $hi = count($flat) - 1; $found = -1;
            while ($lo <= $hi) {
                $mid = (int)(($lo + $hi) / 2);
                if ($startLines[$mid] <= $lineNo) { $found = $mid; $lo = $mid + 1; }
                else $hi = $mid - 1;
            }
            while ($found >= 0 && $lineNo > $flat[$found]['end']) $found--;
            if ($found === -1 || $flat[$found]['noise']) continue;

            $eiKey = (string)$flat[$found]['ei'];
            $liKey = (string)$flat[$found]['li'];

            foreach ($matched as $p) {
                $result[$eiKey][$liKey][] = [
                    'pattern' => $p['pattern'],
                    'label'   => $p['label'],
                    'line_no' => $lineNo,
                ];
            }
        }

        fclose($fh);

        // voter_class post-pass: for PascalCase patterns not matched via sig scan,
        // check voter_class stored in toc — it is a reliable class name, not a raw trace string.
        foreach ($flat as $entry) {
            if (!$entry['voter_class'] || $entry['noise']) continue;
            $eiKey = (string)$entry['ei'];
            $liKey = (string)$entry['li'];
            foreach ($patterns as $p) {
                if ($p['pattern'] === '') continue;
                if (!preg_match('/^[A-Z][A-Za-z0-9]+$/', $p['pattern'])) continue;
                if (!str_contains($entry['voter_class'], $p['pattern'])) continue;
                // Only add if no hit for this pattern already recorded for this listener
                $already = false;
                foreach ($result[$eiKey][$liKey] ?? [] as $h) {
                    if ($h['pattern'] === $p['pattern']) { $already = true; break; }
                }
                if (!$already) {
                    $result[$eiKey][$liKey][] = [
                        'pattern' => $p['pattern'],
                        'label'   => $p['label'],
                        'line_no' => $entry['start'],
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * Returns the ancestor chain from fromLine up to targetLine.
     * fromLine should be the listener's line_no (its depth is the root for this traversal).
     * Result: [{line_no, depth, sig}] ordered root-first, target last.
     * Only includes nodes that are direct ancestors of targetLine (depth strictly increasing path).
     */
    public function getAncestorPath(int $fileId, string $xtPath, int $targetLine, int $fromLine): array
    {
        $indexPath = $this->tracesDir . '/' . $fileId . '/line_index.json';
        $index = json_decode(file_get_contents($indexPath), true);

        // Seek to checkpoint at or before fromLine
        $startOffset = 0;
        $startLine = 0;
        foreach ($index as $indexedLine => $offset) {
            $il = (int)$indexedLine;
            if ($il <= $fromLine) { $startOffset = $offset; $startLine = $il; }
        }

        $fh = fopen($xtPath, 'rb');
        fseek($fh, $startOffset);

        $lineNo = $startLine - 1; // fgets reads startLine first, then lineNo++ = startLine
        $rootDepth = null;
        $stack = []; // monotonically increasing depth path

        while (($line = fgets($fh, 1048576)) !== false) {
            $lineNo++;
            if ($lineNo < $fromLine) continue;
            if ($lineNo > $targetLine) break;

            if (!preg_match(self::CALL_RE, $line, $m)) continue;
            $depth = (int)(strlen($m[1]) / 2);
            $sig   = $this->extractSig($m[2]);

            if ($lineNo === $fromLine) {
                $rootDepth = $depth;
                $stack = [['line_no' => $lineNo, 'depth' => $depth, 'sig' => $sig, 'noise' => false]];
                continue;
            }

            if ($rootDepth === null) continue;

            // Pop stack entries that can't be ancestors of current line
            while (count($stack) && $stack[count($stack)-1]['depth'] >= $depth) {
                array_pop($stack);
            }
            // Always push to maintain correct stack for ancestor tracking,
            // but mark noisy entries so frontend can skip them
            $stack[] = [
                'line_no' => $lineNo,
                'depth'   => $depth,
                'sig'     => $sig,
                'noise'   => $this->isNoisySig($sig),
            ];

            if ($lineNo === $targetLine) break;
        }

        fclose($fh);
        return $stack;
    }

    /**
     * Builds a merged call tree for a set of selected nodes.
     *
     * Strategy: for each selected node, walk its ancestor path (fromLine → line_no).
     * At every ancestor in that path, load all its direct children — so siblings of the
     * selected path are visible too (the "cut-out subgraph" effect).
     * All paths are merged into one tree; common ancestors are deduped by line_no.
     *
     * $items = [{line_no, depth, sig, args, from_line}, ...]
     * Returns [{line_no, depth, sig, args, children: [...], selected: bool}, ...]
     */
    public function buildSchema(int $fileId, string $xtPath, array $items): array
    {
        usort($items, fn($a, $b) => $a['line_no'] <=> $b['line_no']);

        // line_no → node metadata (no children yet — tree assembled in second pass)
        $nodeMap = [];
        // line_no → [child_line_no, ...] — children to include (union across items)
        $childrenOf = [];

        foreach ($items as $item) {
            $lineNo   = (int)$item['line_no'];
            $depth    = (int)$item['depth'];
            $fromLine = (int)($item['from_line'] ?? $lineNo);

            // 1. Ancestor path from listener root to selected node
            $path = $this->getAncestorPath($fileId, $xtPath, $lineNo, $fromLine);

            foreach ($path as $step) {
                if ($step['noise']) continue; // skip noise ancestors entirely
                $ln = $step['line_no'];
                if (!isset($nodeMap[$ln])) {
                    $nodeMap[$ln] = [
                        'line_no'  => $ln,
                        'depth'    => $step['depth'],
                        'sig'      => $step['sig'],
                        'args'     => [],
                        'selected' => false,
                        'noise'    => false,
                    ];
                }
            }

            // Mark the selected node
            if (!isset($nodeMap[$lineNo])) {
                $nodeMap[$lineNo] = [
                    'line_no'  => $lineNo,
                    'depth'    => $depth,
                    'sig'      => $item['sig'] ?? '',
                    'args'     => $item['args'] ?? [],
                    'selected' => true,
                    'noise'    => false,
                ];
            } else {
                $nodeMap[$lineNo]['selected'] = true;
                if (!empty($item['args'])) $nodeMap[$lineNo]['args'] = $item['args'];
            }

            // 2. For every non-noise ancestor, load ALL direct children
            //    so siblings along the path are visible in the schema.
            foreach ($path as $step) {
                if ($step['noise']) continue; // don't expand noise ancestors
                $ln  = $step['line_no'];
                $dep = $step['depth'];
                if (isset($childrenOf[$ln])) continue; // already loaded

                $result = $this->getChildren($fileId, $xtPath, $ln, $dep, true);
                $childrenOf[$ln] = [];
                foreach ($result['children'] as $child) {
                    $cln = $child['line_no'];
                    $childrenOf[$ln][] = $cln;
                    if (!isset($nodeMap[$cln])) {
                        $nodeMap[$cln] = [
                            'line_no'  => $cln,
                            'depth'    => $child['depth'],
                            'sig'      => $child['sig'],
                            'args'     => $child['args'] ?? [],
                            'return'   => $child['return'] ?? null,
                            'selected' => false,
                            'noise'    => false,
                        ];
                    }
                }
            }

            // Also load children of the selected node itself
            if (!isset($childrenOf[$lineNo])) {
                $result = $this->getChildren($fileId, $xtPath, $lineNo, $depth, true);
                $childrenOf[$lineNo] = [];
                foreach ($result['children'] as $child) {
                    $cln = $child['line_no'];
                    $childrenOf[$lineNo][] = $cln;
                    if (!isset($nodeMap[$cln])) {
                        $nodeMap[$cln] = [
                            'line_no'  => $cln,
                            'depth'    => $child['depth'],
                            'sig'      => $child['sig'],
                            'args'     => $child['args'] ?? [],
                            'return'   => $child['return'] ?? null,
                            'selected' => false,
                            'noise'    => false,
                        ];
                    }
                }
            }
        }

        // Assemble tree using explicit childrenOf map (avoids depth-heuristic bugs)
        // Nodes not referenced in any childrenOf list become roots.
        $referenced = [];
        foreach ($childrenOf as $childList) {
            foreach ($childList as $cln) $referenced[$cln] = true;
        }

        // Build node objects with children arrays
        $built = [];
        foreach ($nodeMap as $ln => $meta) {
            $built[$ln] = $meta;
            $built[$ln]['children'] = [];
        }

        foreach ($childrenOf as $parentLn => $childList) {
            if (!isset($built[$parentLn])) continue;
            foreach ($childList as $cln) {
                if (!isset($built[$cln])) continue;
                $built[$parentLn]['children'][] = &$built[$cln];
            }
        }
        unset($cln);

        // Roots = nodes in nodeMap that are not children of anyone
        $roots = [];
        foreach ($built as $ln => &$node) {
            if (!isset($referenced[$ln])) $roots[] = &$node;
        }
        unset($node);

        // Sort roots and children by line_no
        usort($roots, fn($a, $b) => $a['line_no'] <=> $b['line_no']);
        array_walk_recursive($roots, function(&$v) {}); // noop, already sorted via built

        return $roots;
    }

    public function getLines(int $fileId, string $xtPath, int $from, int $to): array
    {
        $indexPath = $this->tracesDir . '/' . $fileId . '/line_index.json';
        $index = json_decode(file_get_contents($indexPath), true);

        $startOffset = 0;
        $startLine = 0;
        foreach ($index as $indexedLine => $offset) {
            $il = (int)$indexedLine;
            if ($il <= $from) {
                $startOffset = $offset;
                $startLine = $il;
            }
        }

        $fh = fopen($xtPath, 'rb');
        fseek($fh, $startOffset);

        $lines = [];
        $lineNo = $startLine;

        while (($line = fgets($fh, 1048576)) !== false) {
            $lineNo++;
            if ($lineNo < $from) continue;
            if ($lineNo > $to) break;

            $depth = 0;
            $sig = null;
            if (preg_match(self::CALL_RE, $line, $m)) {
                $depth = (int)(strlen($m[1]) / 2);
                $sig = $this->extractSig($m[2]);
            }

            $lines[] = [
                'line_no' => $lineNo,
                'depth'   => $depth,
                'sig'     => $sig,
                'raw'     => rtrim($line),
            ];
        }

        fclose($fh);
        return $lines;
    }

    public function search(int $fileId, string $xtPath, string $query, int $limit = 200): array
    {
        $fh = fopen($xtPath, 'rb');
        $lineNo = 0;
        $results = [];

        while (($line = fgets($fh, 1048576)) !== false) {
            $lineNo++;

            if (stripos($line, $query) === false) continue;
            if (!preg_match(self::CALL_RE, $line, $m)) continue;

            $results[] = [
                'line_no' => $lineNo,
                'depth'   => (int)(strlen($m[1]) / 2),
                'sig'     => $this->extractSig($m[2]),
            ];

            if (count($results) >= $limit) break;
        }

        fclose($fh);
        return $results;
    }

    // Sigs to always hide from children (pure noise, no semantic value)
    private const CHILD_NOISE = [
        // PHP builtins (no backslash in sig)
        'is_null', 'is_string', 'is_array', 'is_int', 'is_bool', 'is_numeric', 'is_callable',
        'is_object', 'is_float', 'strlen', 'count', 'method_exists', 'class_exists',
        'function_exists', 'in_array', 'array_key_exists', 'array_merge', 'array_map',
        'array_filter', 'array_pop', 'array_shift', 'array_unique', 'array_reverse',
        'array_keys', 'array_values', 'str_contains', 'str_starts_with', 'str_ends_with',
        'sprintf', 'strtolower', 'strtoupper', 'trim', 'ltrim', 'rtrim', 'substr',
        'strpos', 'strrpos', 'str_replace', 'preg_match', 'preg_replace', 'explode',
        'implode', 'end', 'reset', 'krsort', 'ksort', 'usort', 'sort',
        'round', 'microtime', 'time', 'memory_get_usage', 'get_debug_type',
        'func_get_arg', 'get_parent_class', 'error_reporting', 'opcache_is_script_cached',
        // DI container noise
        'Container->getService', 'ServiceLocator->get', 'ServiceLocator->has',
        // Debug/classloader noise
        'DebugClassLoader->loadClass', 'DebugClassLoader->checkClass', 'DebugClassLoader->checkAnnotations',
        'DebugClassLoader->parsePhpDoc',
        // Stopwatch noise
        'Stopwatch->start', 'Stopwatch->stop', 'StopwatchEvent->start', 'StopwatchEvent->stop',
        'StopwatchEvent->getNow', 'StopwatchEvent->formatTime', 'StopwatchPeriod->__construct',
        'StopwatchEvent->__construct', 'Section->startEvent',
        // EventDispatcher infrastructure noise (visible in ancestor paths, not semantic)
        'TraceableEventDispatcher->preProcess', 'TraceableEventDispatcher->beforeDispatch',
        'TraceableEventDispatcher->afterDispatch', 'TraceableEventDispatcher->postProcess',
        'WrappedListener->__invoke', 'EventDispatcher->dispatch',
        // VirtualRequestStack infra
        'VirtualRequestStack->getCurrentRequest',
        // Reflection noise
        'ReflectionClass->__construct', 'ReflectionClass->getName', 'ReflectionClass->getDocComment',
        'ReflectionExtractor->getMethodsFlags', 'ReflectionExtractor->getPropertyFlags',
        // Trivial no-arg getters that repeat without info
        '->isPropagationStopped', '->getWrappedListener',
        // KernelEvent/RequestEvent/ResponseEvent boilerplate getters
        'KernelEvent->getRequest', 'KernelEvent->isMainRequest', 'KernelEvent->isMasterRequest',
        'KernelEvent->getKernel', 'RequestEvent->getRequest', 'ResponseEvent->getResponse',
        'ViewEvent->getControllerResult', 'FinishRequestEvent->__construct',
        'ExceptionEvent->getThrowable', 'ExceptionEvent->isAllowingCustomResponseCode',
        'Cookie->getName', 'Cookie->getValue', 'Cookie->getDomain', 'Cookie->getPath',
        'Cookie->withSecure', 'Cookie->withHttpOnly', 'Cookie->withSameSite',
        // Cache control noise
        'ResponseHeaderBag->hasCacheControlDirective', 'HeaderBag->addCacheControlDirective',
        'Response->setMaxAge', 'Response->setPrivate', 'Response->setExpires', 'Response->getMaxAge',
        'DateTimeImmutable->__construct',
    ];

    /**
     * Returns direct children of the call at $lineNo (which has $callDepth).
     * Seeks to lineNo, reads until depth returns to <= callDepth, collects depth===callDepth+1 calls.
     */
    public function getChildren(int $fileId, string $xtPath, int $lineNo, int $callDepth, bool $filter = true): array
    {
        $indexPath = $this->tracesDir . '/' . $fileId . '/line_index.json';
        $index = json_decode(file_get_contents($indexPath), true);

        $startOffset = 0;
        $startLine = 0;
        foreach ($index as $indexedLine => $offset) {
            $il = (int)$indexedLine;
            if ($il <= $lineNo) { $startOffset = $offset; $startLine = $il; }
        }

        $fh = fopen($xtPath, 'rb');
        fseek($fh, $startOffset);

        $children = [];
        $rawChildCount = 0;
        $currentLine = $startLine - 1;
        $childDepth = $callDepth + 1;
        $foundParent = false;
        $lastChildIdx = null; // index into $children for attaching return value
        $parentReturn = null;

        while (($line = fgets($fh, 1048576)) !== false) {
            $currentLine++;
            if ($currentLine < $lineNo) continue;

            // Return value line: "   >=> value"
            if (preg_match('/^([ ]*)>=>\s*(.+)$/', $line, $rm)) {
                $retDepth = (int)(strlen($rm[1]) / 2);
                if ($retDepth === $callDepth && $foundParent) {
                    // Return value of the parent itself
                    $val = $this->simplifyValue(trim($rm[2]));
                    $parentReturn = $val ?? trim(substr($rm[2], 0, 80));
                    break;
                }
                if ($lastChildIdx !== null && $retDepth === $childDepth) {
                    $val = $this->simplifyValue(trim($rm[2]));
                    $children[$lastChildIdx]['return'] = $val ?? trim(substr($rm[2], 0, 80));
                }
                $lastChildIdx = null;
                continue;
            }

            if (!preg_match(self::CALL_RE, $line, $m)) {
                $lastChildIdx = null;
                continue;
            }
            $depth = (int)(strlen($m[1]) / 2);

            // First matching line at callDepth is the parent itself — skip it
            if (!$foundParent) {
                if ($depth === $callDepth) $foundParent = true;
                continue;
            }

            // Left the parent scope
            if ($depth <= $callDepth) break;

            $lastChildIdx = null;
            if ($depth === $childDepth) {
                $sig = $this->extractSig($m[2]);
                $rawChildCount++;

                // Close previous child's subtree_end
                if ($children) {
                    $children[count($children) - 1]['subtree_end'] = $currentLine - 1;
                }

                if ($filter && $this->isNoisySig($sig)) continue;

                $rawFile = $this->extractFile($m[2]);
                $children[] = [
                    'line_no'        => $currentLine,
                    'depth'          => $depth,
                    'sig'            => $sig,
                    'args'           => $this->extractArgs($m[2]),
                    'file'           => $rawFile ? $this->shortFile($rawFile) : null,
                    'file_abs'       => $rawFile,
                    'return'         => null,
                    'subtree_end'    => null,
                    'noise_only'     => false,  // filled below
                ];
                $lastChildIdx = count($children) - 1;
            } elseif ($depth === $childDepth + 1 && $children) {
                // Grandchild line — track whether last child has only noisy grandchildren
                $grandSig = $this->extractSig($m[2]);
                $last = &$children[count($children) - 1];
                if (!isset($last['_grand_total'])) { $last['_grand_total'] = 0; $last['_grand_noisy'] = 0; }
                $last['_grand_total']++;
                if ($this->isNoisySig($grandSig)) $last['_grand_noisy']++;
            }
        }

        // Close last child's subtree_end at end of parent scope
        if ($children) {
            $children[count($children) - 1]['subtree_end'] = $currentLine;
        }

        // Finalize noise_only flag and clean up temp fields
        foreach ($children as &$child) {
            $total = $child['_grand_total'] ?? 0;
            $noisy = $child['_grand_noisy'] ?? 0;
            $child['noise_only'] = $total > 0 && $total === $noisy;
            unset($child['_grand_total'], $child['_grand_noisy']);
        }
        unset($child);

        fclose($fh);
        return ['children' => $children, 'parent_return' => $parentReturn, 'raw_count' => $rawChildCount];
    }

    private function isNoisySig(string $sig): bool
    {
        // PHP builtins have no backslash and no ->
        if (!str_contains($sig, '\\') && !str_contains($sig, '->') && !str_contains($sig, '::')) {
            return true;
        }
        // Check against noise list (match by suffix)
        foreach (self::CHILD_NOISE as $noise) {
            if (str_ends_with($sig, $noise)) return true;
        }
        return false;
    }

    private function extractSig(string $call): string
    {
        $paren = strpos($call, '(');
        return $paren !== false ? trim(substr($call, 0, $paren)) : trim($call);
    }

    /**
     * Extracts readable args from xdebug call string.
     * Keeps only simple scalars and class names — skips huge object dumps.
     */
    private function extractArgs(string $call): array
    {
        $paren = strpos($call, '(');
        if ($paren === false) return [];

        $argsStr = substr($call, $paren + 1);
        // Remove trailing )
        $argsStr = preg_replace('/\)\s*$/', '', $argsStr);
        if (trim($argsStr) === '' || $argsStr === '???') return [];

        // Split on top-level commas (not inside braces/brackets)
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
            // Format: "$name = value"
            if (preg_match('/^\$(\w+)\s*=\s*(.+)$/', $arg, $am)) {
                $name = $am[1];
                $val  = $this->simplifyValue(trim($am[2]));
                if ($val !== null) {
                    $result[] = '$' . $name . ' = ' . $val;
                }
            }
        }
        return $result;
    }

    private function simplifyValue(string $val): ?string
    {
        // String literal (truncate long values like JWTs)
        if (preg_match("/^'(.{0,200})'\s*$/s", $val, $m)) {
            $s = $m[1];
            // Truncate JWT tokens and other long strings
            if (strlen($s) > 40 && preg_match('/^ey[A-Za-z0-9]/', $s)) return "'<JWT>'";
            if (strlen($s) > 60) return "'" . substr($s, 0, 57) . "...'";
            return "'" . $s . "'";
        }
        // Integer / float / bool / null
        if (preg_match('/^(TRUE|FALSE|NULL|-?\d+\.?\d*)$/i', $val)) return $val;
        // Cookie object — extract name from any visibility: "class ...Cookie { ... $name = 'sio_u' ..."
        if (preg_match('/^class\s+[\w\\\\]*Cookie[\s{]/', $val)
            && preg_match('/\$name\s*=\s*\'([^\']+)\'/', $val, $m)) {
            return "Cookie('" . $m[1] . "')";
        }
        // Short class name: "class Foo\Bar { ... }" → "Bar {…}"
        if (preg_match('/^class\s+([\w\\\\]+)/', $val, $m)) {
            $parts = explode('\\', $m[1]);
            return end($parts) . ' {…}';
        }
        // enum: "enum Foo\Bar::Name('value')" → "Bar::Name"
        if (preg_match('/^enum\s+([\w\\\\]+)::([\w]+)/', $val, $m)) {
            $parts = explode('\\', $m[1]);
            return end($parts) . '::' . $m[2];
        }
        // Array short form
        if (str_starts_with($val, '[') || str_starts_with($val, 'array(')) return '[…]';
        // Skip unknown/huge values
        return null;
    }

    /**
     * Reads the raw arg value at $argIdx from the call at $lineNo.
     * Returns parsed object fields if it's a class dump, or null.
     */
    public function getObjectArg(int $fileId, string $xtPath, int $lineNo, int $argIdx): ?array
    {
        $indexPath = $this->tracesDir . '/' . $fileId . '/line_index.json';
        $index = json_decode(file_get_contents($indexPath), true);

        $startOffset = 0;
        $startLine = 0;
        foreach ($index as $indexedLine => $offset) {
            $il = (int)$indexedLine;
            if ($il <= $lineNo) { $startOffset = $offset; $startLine = $il; }
        }

        $fh = fopen($xtPath, 'rb');
        fseek($fh, $startOffset);
        $currentLine = $startLine - 1;
        $rawLine = null;

        while (($line = fgets($fh, 1048576)) !== false) {
            $currentLine++;
            if ($currentLine === $lineNo) { $rawLine = $line; break; }
        }
        fclose($fh);

        if ($rawLine === null || !preg_match(self::CALL_RE, $rawLine, $m)) return null;

        $rawArgs = $this->splitRawArgs($m[2]);
        if (!isset($rawArgs[$argIdx])) return null;

        $arg = $rawArgs[$argIdx];
        // Format: "$name = value"
        if (!preg_match('/^\$\w+\s*=\s*(.+)$/s', $arg, $am)) return null;
        $val = trim($am[1]);

        return $this->parseObjectFields($val);
    }

    /**
     * Splits raw args string into individual args (top-level comma split).
     */
    private function splitRawArgs(string $call): array
    {
        $paren = strpos($call, '(');
        if ($paren === false) return [];
        $argsStr = substr($call, $paren + 1);
        $argsStr = preg_replace('/\)\s*$/', '', $argsStr);
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
        return $args;
    }

    /**
     * Parses "class Foo\Bar { protected ?int $id = 13; private string $email = 'x'; ... }"
     * → ['class' => 'Bar', 'full_class' => 'Foo\Bar', 'fields' => [...]]
     *
     * xdebug format: each field is "<visibility> <type> $name = <value>"
     * separated by "; " at top level (not inside nested { }).
     */
    private function parseObjectFields(string $val): ?array
    {
        if (!preg_match('/^class\s+([\w\\\\]+)\s*\{(.+)\}\s*$/s', $val, $m)) return null;

        $fullClass = $m[1];
        $parts = explode('\\', $fullClass);
        $shortClass = end($parts);
        $body = $m[2];

        // Split body into top-level ";" separated segments
        // (don't split inside nested { } or [ ])
        $segments = [];
        $depth = 0;
        $current = '';
        for ($i = 0; $i < strlen($body); $i++) {
            $ch = $body[$i];
            if ($ch === '{' || $ch === '[' || $ch === '(') $depth++;
            elseif ($ch === '}' || $ch === ']' || $ch === ')') $depth--;
            elseif ($ch === ';' && $depth === 0) {
                $segments[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $ch;
        }
        if (trim($current) !== '') $segments[] = trim($current);

        $fields = [];
        foreach ($segments as $seg) {
            // Each segment: "public|protected|private|static [readonly] [?TypeHint] $name = value"
            // or just "$name = value" (public properties in older format)
            if (!preg_match('/(?:(?:public|protected|private|static|readonly)\s+)*(?:[\w\\\\?|&]+\s+)?\$(\w+)\s*=\s*(.+)$/s', $seg, $sm)) continue;
            $name = $sm[1];
            $rawVal = trim($sm[2]);
            $simplified = $this->simplifyValue($rawVal);
            $fields[] = [
                'name'       => $name,
                'value'      => $simplified ?? $this->truncateRaw($rawVal),
                'expandable' => str_starts_with($rawVal, 'class ') || str_starts_with($rawVal, '['),
            ];
        }

        return ['class' => $shortClass, 'full_class' => $fullClass, 'fields' => $fields];
    }

    private function truncateRaw(string $val): string
    {
        $short = preg_replace('/\s+/', ' ', $val);
        return strlen($short) > 80 ? substr($short, 0, 77) . '...' : $short;
    }

    // Extract "/path/file.php:N" suffix from raw call group (m[2]) without regex backtracking.
    // Returns null if no file suffix found.
    private function extractFile(string $raw): ?string
    {
        // File suffix always looks like " /absolute/path.php:N" at the end of the line.
        // Find last " /" occurrence and check it looks like a file path.
        $pos = strrpos($raw, ' /');
        if ($pos === false) return null;
        $candidate = substr($raw, $pos + 1); // "/path/file.php:123"
        if (preg_match('#^/[^\s]+\.php:\d+$#', $candidate)) return $candidate;
        return null;
    }

    private function shortFile(string $fileColon): string
    {
        // "/var/www/monolith-backend/vendor/symfony/http-kernel/EventListener/Foo.php:123"
        // → "EventListener/Foo.php:123"
        if (preg_match('#/(src|vendor)/(.+)$#', $fileColon, $m)) {
            $parts = explode('/', $m[2]);
            // keep last 2-3 segments
            return implode('/', array_slice($parts, max(0, count($parts) - 3)));
        }
        return basename($fileColon);
    }
}
