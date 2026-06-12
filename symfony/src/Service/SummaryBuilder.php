<?php

namespace App\Service;

use App\Entity\TraceFile;
use App\Repository\AnnotationRepository;
use App\Repository\EndpointTimingRepository;
use App\Repository\FavouritePatternRepository;

/**
 * Builds a single AI-friendly markdown summary of a parsed trace file.
 *
 * The output is intended to be pasted into a chat with an LLM (Claude, etc.)
 * so it can understand the request, the open TOC tree, SQL chronology (incl.
 * N+1 patterns), user annotations, and timing data without an interactive
 * back-and-forth.
 *
 * Sections are independently switchable via the $sections argument so the
 * frontend and the MCP tool can ask for the same shape with different cuts.
 */
class SummaryBuilder
{
    /** Soft cap on the total text length. Sections that would push us past
     *  this are truncated; the caller is told via the `truncated` flag. */
    private const HARD_CHAR_CAP = 200_000;

    public function __construct(
        private readonly string $tracesDir,
        private readonly AnnotationRepository $annotRepo,
        private readonly FavouritePatternRepository $favRepo,
        private readonly EndpointTimingRepository $timingRepo,
    ) {}

    /**
     * @return array{text: string, stats: array<string, int|bool>, truncated: bool}
     */
    public function build(
        TraceFile $traceFile,
        array $sections = ['context', 'toc', 'sql', 'annotations', 'timings'],
        int $maxQueries = 200,
        bool $includeQb = true,
    ): array {
        $stats = [
            'events'         => 0,
            'listeners'      => 0,
            'sql_queries'    => 0,
            'sql_n_plus_1'   => 0,
            'annotations'    => 0,
            'timings'        => 0,
            'favourites'     => 0,
            'sections_included' => count($sections),
        ];
        $truncated = false;

        $dir = $this->tracesDir . '/' . $traceFile->getId();
        $toc  = $this->loadJson($dir . '/toc.json',  []);
        $sql  = $this->loadJson($dir . '/sql.json',  []);
        $meta = $this->loadJson($dir . '/meta.json', []);

        $stats['sql_queries'] = count($sql);
        $stats['events']      = count($toc);

        $parts = [];

        // Compute timings/annotations counts FIRST so the header line in
        // buildHeader() can show accurate stats (each builder increments
        // $stats by reference, so we have to run them before the header).
        if (in_array('timings', $sections, true))     $this->buildTimings($traceFile, $stats);
        if (in_array('annotations', $sections, true)) $this->buildAnnotations($traceFile, $stats);
        if (in_array('favourites', $sections, true)) {
            $stats['favourites'] = count($this->favRepo->findAll());
        }

        $parts[] = $this->buildHeader($traceFile, $meta, $stats, $sections);

        // NB: call section builders directly (not via a closure map) — closures
        // don't reliably propagate by-reference updates back to the caller in
        // PHP, so $stats mutations in buildToc/buildSql/etc. would be lost.
        foreach ($sections as $s) {
            $sectionText = match ($s) {
                'context'     => $this->buildContext($meta),
                'toc'         => $this->buildToc($toc, $stats),
                'sql'         => $this->buildSql($sql, $maxQueries, $includeQb, $dir, $stats),
                'annotations' => $this->buildAnnotations($traceFile, $stats),
                'timings'     => $this->buildTimings($traceFile, $stats),
                default       => null,
            };
            if ($sectionText !== null && $sectionText !== '') {
                $parts[] = $sectionText;
            }
        }

        $text = implode("\n\n", $parts);

        if (mb_strlen($text) > self::HARD_CHAR_CAP) {
            $text = mb_substr($text, 0, self::HARD_CHAR_CAP) . "\n\n… (truncated at 200k chars — re-call with `max_queries` lower for a shorter summary)";
            $truncated = true;
        }

        $stats['chars'] = mb_strlen($text);

        return [
            'text'      => $text,
            'stats'     => $stats,
            'truncated' => $truncated,
        ];
    }

    // ── Section builders ──────────────────────────────────────────────────

    private function buildHeader(TraceFile $traceFile, array $meta, array $stats, array $sections): string
    {
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $file = $traceFile->getOriginalName();
        $totalLines = $meta['total_lines'] ?? 0;
        $list = implode(', ', $sections);

        $lines = [
            "# XTrace summary — {$now}",
            "",
            "file: `{$file}`",
            "id: `{$traceFile->getId()}` · sections: `{$list}`",
            "total lines: " . number_format($totalLines) .
                " · events: {$stats['events']}" .
                " · SQL queries: {$stats['sql_queries']}" .
                " · annotations: {$stats['annotations']}" .
                " · timings: {$stats['timings']}",
        ];
        return implode("\n", $lines);
    }

    private function buildContext(array $meta): ?string
    {
        $req = $meta['request']  ?? null;
        $res = $meta['response'] ?? null;
        if (!$req && !$res) return null;

        $lines = ["## Context"];
        if ($req) {
            // meta.json uses 'uri' for the path; 'request_time' (when present) is a unix
            // timestamp (not seconds), so we don't surface it as a duration.
            $method  = $req['method']  ?? '?';
            $path    = $req['uri']     ?? ($req['url'] ?? '?');
            $host    = $req['host']    ?? '';
            $query   = $req['query']   ?? '';
            $started = $req['started_at'] ?? '?';
            $fullUrl = $host ? "{$host}{$path}" : $path;
            if ($query) $fullUrl .= "?{$query}";
            $lines[] = "- request: `{$method} /{$fullUrl}` (started {$started})";
        }
        if ($res) {
            $status = $res['status'] ?? ($res['status_code'] ?? '?');
            $size   = $res['size'] ?? null;
            $loc    = $res['location'] ?? null;
            $locStr = $loc ? " → `{$loc}`" : '';
            $sizeStr = $size !== null ? ' · ' . $this->formatBytes((int)$size) : '';
            $lines[] = "- response: `{$status}`{$sizeStr}{$locStr}";
        }
        // Favourites: what the user is tracking on this file (cross-cutting, not in toc.json)
        $favs = $this->favRepo->findAll();
        if ($favs) {
            $names = [];
            foreach ($favs as $f) {
                $names[] = $f->getLabel()
                    ? $f->getPattern() . ' (' . $f->getLabel() . ')'
                    : $f->getPattern();
            }
            $lines[] = "- favourites tracked: " . implode(', ', $names);
        }
        return implode("\n", $lines);
    }

    private function buildToc(array $toc, array &$stats): ?string
    {
        if (!$toc) return null;

        $lines = ["## TOC (events → listeners → first App\\ calls)"];
        $listenersTotal = 0;
        foreach ($toc as $idx => $event) {
            $eventName = $event['event'] ?? '?';
            $type = $event['type'] ?? 'event';
            $appCalls = $event['app_calls'] ?? [];   // App\ call roots — not nested events
            $listeners = $event['listeners'] ?? [];
            $listenersTotal += count($listeners);

            $header = $eventName;
            if ($type === 'controller_execution') $header = '[controller] ' . $this->shortSig($eventName);
            $lines[] = "- {$header}";

            foreach ($listeners as $li => $l) {
                $sig = $this->shortSig($l['sig'] ?? '?');
                $voter = $l['voter_class'] ?? null;
                $voteResult = $l['vote_result'] ?? null;
                $tag = '';
                if ($voter && $voteResult !== null) {
                    $tag = sprintf(' [voter=%s, %s]', $voter, $this->voteResult($voteResult));
                } elseif ($voter) {
                    $tag = sprintf(' [voter=%s]', $voter);
                }
                $lines[] = "  - {$sig}@:{$l['line_no']}{$tag}";
            }
            // First 3 App\ call roots for context — full tree would be too much.
            // Skip __construct — those are DI container noise, not real call sites.
            $shown = 0;
            foreach ($appCalls as $c) {
                if ($shown >= 3) break;
                $cSig = $c['sig'] ?? '';
                if ($cSig === '' || str_ends_with($cSig, '->__construct')) continue;
                $cLine = $c['line_no'] ?? 0;
                $lines[] = "    → {$this->shortSig($cSig)}@:{$cLine}";
                $shown++;
            }
        }
        $stats['listeners'] = $listenersTotal;
        return implode("\n", $lines);
    }

    private function buildSql(array $sql, int $maxQueries, bool $includeQb, string $dir, array &$stats): ?string
    {
        if (!$sql) {
            $stats['sql_n_plus_1'] = 0;
            return "## SQL chronology\n\n(no SQL extracted — reparse to generate sql.json)";
        }

        // Group by toc → caller → unique SQL (same logic as the frontend's SqlPage.vue callerGroups)
        $tocMap = [];
        $allLineNos = [];
        foreach ($sql as $q) {
            $tocKey = $q['toc'] ?? '(no context)';
            // caller can be null for queries triggered from outside App\ (vendor, kernel hooks).
            // Use nested null-coalescing via isset() to avoid "undefined key" warnings.
            $caller = is_array($q['caller'] ?? null) ? $q['caller'] : [];
            $callerSig   = $caller['sig']     ?? '(no caller)';
            $callerFile  = $caller['file']    ?? '';
            $callerLineNo = $caller['line_no'] ?? null;
            $callerDepth = $caller['depth']   ?? null;
            $normKey = $this->normalizeSqlKey($q['sql'] ?? '');

            if (!isset($tocMap[$tocKey])) {
                $tocMap[$tocKey] = [
                    'label'     => $this->shortToc($tocKey),
                    'firstLine' => $q['line_no'],
                    'total'     => 0,
                    'callers'   => [],
                ];
            }
            $tg = &$tocMap[$tocKey];
            $tg['total']++;
            if ($q['line_no'] < $tg['firstLine']) $tg['firstLine'] = $q['line_no'];

            if (!isset($tg['callers'][$callerSig])) {
                $tg['callers'][$callerSig] = [
                    'sig'         => $callerSig,
                    'short'       => $this->shortSig($callerSig),
                    'file'        => $callerFile,
                    'count'       => 0,
                    'totalMs'     => 0,
                    'firstLine'   => $q['line_no'],
                    'callerLineNo' => $callerLineNo,
                    'callerDepth' => $callerDepth,
                    'uqs'         => [],
                ];
            }
            $cg = &$tg['callers'][$callerSig];
            $cg['count']++;
            if ($q['line_no'] < $cg['firstLine']) $cg['firstLine'] = $q['line_no'];

            if (!isset($cg['uqs'][$normKey])) {
                $cg['uqs'][$normKey] = [
                    'key'      => $normKey,
                    'sql'      => $q['sql'] ?? '',
                    'count'    => 0,
                    'totalMs'  => 0.0,
                    'firstLine' => $q['line_no'],
                    'instances' => [],
                    'sampleParams' => $q['params'] ?? [],
                ];
            }
            $uq = &$cg['uqs'][$normKey];
            $uq['count']++;
            $uq['totalMs'] += (float)($q['duration_ms'] ?? 0);
            if ($q['line_no'] < $uq['firstLine']) $uq['firstLine'] = $q['line_no'];
            $uq['instances'][] = ['line_no' => $q['line_no'], 'n' => $q['n']];
            $allLineNos[] = $q['line_no'];
            unset($tg, $cg, $uq);
        }

        // Count N+1: unique SQL executed more than once with single-table-where
        $nPlus1Count = 0;
        foreach ($tocMap as $tg) {
            foreach ($tg['callers'] as $cg) {
                foreach ($cg['uqs'] as $uq) {
                    if ($uq['count'] > 1 && $this->looksLikeEager($uq['sql'])) {
                        $nPlus1Count++;
                    }
                }
            }
        }
        $stats['sql_n_plus_1'] = $nPlus1Count;

        // Sort toc groups chronologically
        uasort($tocMap, fn($a, $b) => $a['firstLine'] <=> $b['firstLine']);

        $lines = [
            "## SQL chronology",
            "",
            "queries: " . count($sql) . " · N+1 candidates: {$nPlus1Count}" . ($maxQueries < count($sql) ? " · showing first {$maxQueries} of " . count($sql) : ''),
        ];

        $emitted = 0;
        // Total unique SQL patterns (across all toc×caller groups) — used to phrase
        // the "X more omitted" footer in terms of distinct patterns, not raw
        // instance counts (which include N+1 duplications).
        $totalUnique = array_sum(array_map(fn($tg) => array_sum(array_map(fn($cg) => count($cg['uqs']), $tg['callers'])), $tocMap));
        $qbCache = []; // caller line_no → QB chain (lazy fetched on demand)
        foreach ($tocMap as $tgKey => $tg) {
            if ($emitted >= $maxQueries) break;
            $lines[] = "";
            $lines[] = "### {$tg['label']} — {$tg['total']} quer" . ($tg['total'] === 1 ? 'y' : 'ies');

            uasort($tg['callers'], fn($a, $b) => $a['firstLine'] <=> $b['firstLine']);
            foreach ($tg['callers'] as $cg) {
                if ($emitted >= $maxQueries) break;
                $fileHint = $cg['file'] ? " `{$cg['file']}`" : '';
                $msHint = $cg['totalMs'] > 0
                    ? sprintf(' · ~%s', $this->fmtMs($cg['totalMs']))
                    : '';
                $lines[] = "- {$cg['short']}{$fileHint} — {$cg['count']} quer" . ($cg['count'] === 1 ? 'y' : 'ies') . $msHint;

                // Sort unique queries chronologically
                uasort($cg['uqs'], fn($a, $b) => $a['firstLine'] <=> $b['firstLine']);
                foreach ($cg['uqs'] as $uq) {
                    if ($emitted >= $maxQueries) break;
                    $emitted++;
                    $label = ($uq['count'] > 1 && $this->looksLikeEager($uq['sql'])) ? '⚡ eager' : null;
                    $timing = '';
                    if ($uq['count'] > 1 && $uq['totalMs'] > 0) {
                        $timing = sprintf(' (~%s total)', $this->fmtMs($uq['totalMs']));
                    } elseif ($uq['count'] === 1 && $uq['totalMs'] > 0) {
                        $timing = sprintf(' (~%s)', $this->fmtMs($uq['totalMs']));
                    }
                    $countStr = $uq['count'] > 1 ? sprintf('×%d ', $uq['count']) : '';
                    $kindTag = $label ? " ({$label})" : '';
                    $lines[] = sprintf("  - %s%s%s%s", $countStr, $uq['sql'] !== '' ? '`' . mb_substr($uq['sql'], 0, 200) . '`' : '(empty SQL)', $timing, $kindTag);

                    if (!empty($uq['sampleParams'])) {
                        $params = array_slice($uq['sampleParams'], 0, 3);
                        $more = count($uq['sampleParams']) > 3 ? ', …' : '';
                        $lines[] = "    params: [" . implode(', ', array_map(fn($p) => "`{$p}`", $params)) . $more . "]";

                        $lineNos = array_slice(array_column($uq['instances'], 'line_no'), 0, 5);
                        $moreLines = count($uq['instances']) > 5 ? sprintf(' …+%d more', count($uq['instances']) - 5) : '';
                        $lines[] = "    lines: " . implode(', ', array_map(fn($l) => ':' . $l, $lineNos)) . $moreLines;
                    }

                    // Fetch QB chain for the *first instance* of a query — we want the App\ caller
                    // (which is the same for all instances of this unique query) and its QB calls.
                    if ($includeQb && $cg['callerLineNo'] !== null) {
                        $chain = $this->fetchQbChain($dir, $cg['callerLineNo'], $cg['callerDepth'], $qbCache);
                        if (!empty($chain)) {
                            // xdebug writes a single fluent chain on one line, so the
                            // OUTER call's args = "the rest of the line" which contains
                            // every nested ->method(...) call. We flatten that into
                            // a readable sequence of method names + first-arg peek.
                            $qbText = '$qb';
                            foreach ($chain as $call) {
                                $args = $call['args'];
                                if (mb_strlen($args) > 40) $args = mb_substr($args, 0, 40) . '…';
                                if ($args === '') {
                                    $qbText .= '->' . $call['method'] . '()';
                                } else {
                                    $qbText .= '->' . $call['method'] . '(' . $args . ')';
                                }
                            }
                            if (!str_ends_with($qbText, '->getResult()')) {
                                $qbText .= '->getResult()';
                            }
                            $lines[] = "    QB: `{$qbText}`";
                        }
                    }
                }
            }
        }

        if ($emitted < $totalUnique) {
            $lines[] = "";
            $lines[] = "… " . ($totalUnique - $emitted) . " more unique SQL patterns omitted (use `max_queries` to raise the cap)";
        }

        return implode("\n", $lines);
    }

    private function buildAnnotations(TraceFile $traceFile, array &$stats): ?string
    {
        $annots = $this->annotRepo->findByTraceFile($traceFile->getId());
        $stats['annotations'] = count($annots);
        if (!$annots) return null;

        $lines = ["## Annotations (" . count($annots) . ")"];
        foreach ($annots as $a) {
            $text = $a->getText();
            if (mb_strlen($text) > 200) $text = mb_substr($text, 0, 200) . '…';
            $lines[] = sprintf("- :%d %s", $a->getLineNo(), $text);
        }
        return implode("\n", $lines);
    }

    private function buildTimings(TraceFile $traceFile, array &$stats): ?string
    {
        $timings = $this->timingRepo->findByTraceName($traceFile->getOriginalName(), 50);
        $stats['timings'] = count($timings);
        if (!$timings) return null;

        $lines = ["## Backend timings (last " . count($timings) . ")"];
        foreach ($timings as $t) {
            $url = $t->getEndpointUrl();
            $method = $t->getEndpointMethod();
            $ms = $t->getDurationMs();
            $created = $t->getCreatedAt()->format('H:i:s');
            $lines[] = sprintf("- %s %-7s  %sms  %s", $created, $method, $ms, $url);
        }
        return implode("\n", $lines);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function loadJson(string $path, mixed $default): mixed
    {
        if (!file_exists($path)) return $default;
        $decoded = json_decode(file_get_contents($path), true);
        return $decoded ?? $default;
    }

    private function shortSig(string $sig): string
    {
        if (!str_contains($sig, '->') && !str_contains($sig, '::')) {
            return $sig;
        }
        $sep = str_contains($sig, '::') ? '::' : '->';
        $parts = explode($sep, $sig, 2);
        $classParts = explode('\\', $parts[0]);
        $class = end($classParts);
        return $class . $sep . ($parts[1] ?? '');
    }

    private function shortToc(string $toc): string
    {
        $parts = explode('\\', $toc);
        return end($parts) ?: $toc;
    }

    private function normalizeSqlKey(string $sql): string
    {
        $s = preg_replace('/\b[a-z]\d+_/', '', $sql);
        $s = preg_replace('/\s+/', ' ', $s);
        return mb_substr(trim($s), 0, 150);
    }

    /**
     * Heuristic N+1 detector: a unique SQL executed many times against a single
     * table (no JOIN). This catches the classic Doctrine EAGER pattern where a
     * getter on a collection triggers one SELECT per parent.
     */
    private function looksLikeEager(string $sql): bool
    {
        if (!$sql) return false;
        $hasJoin = (bool)preg_match('/\bJOIN\b/i', $sql);
        $hasMultiTable = (bool)preg_match('/\bFROM\s+\w+\.\w+/i', $sql);
        // A single SELECT against one table alias is the EAGER signature.
        return !$hasJoin && !$hasMultiTable;
    }

    private function voteResult(int $r): string
    {
        return match ($r) {
            1      => 'GRANTED',
            -1     => 'DENIED',
            default => 'ABSTAIN',
        };
    }

    private function fmtMs(float $ms): string
    {
        if ($ms >= 1000) return number_format($ms / 1000, 1) . 's';
        if ($ms >= 10)   return (string)(int)round($ms) . 'ms';
        return number_format($ms, 1) . 'ms';
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes > 1_000_000) return number_format($bytes / 1_000_000, 1) . ' MB';
        if ($bytes > 1_000)     return number_format($bytes / 1_000, 0) . ' KB';
        return $bytes . ' B';
    }

    /**
     * Fetch the QueryBuilder chain for a caller's children — same heuristic as
     * SqlPage.vue on the frontend. Cached per (line_no, depth) within a single
     * build() call so we don't re-read the same listener subtree.
     *
     * xdebug writes a fluent chain as SEPARATE call lines, one per ->method(),
     * e.g. a 3-link chain spans 3 trace lines at increasing depths. We walk
     * until the parent's scope closes, picking up every QueryBuilder call.
     *
     * @return list<array{method: string, args: string}>
     */
    private function fetchQbChain(string $dir, int $lineNo, ?int $depth, array &$cache): array
    {
        $cacheKey = $lineNo . ':' . ($depth ?? 0);
        if (array_key_exists($cacheKey, $cache)) return $cache[$cacheKey];

        $lineIndexPath = $dir . '/line_index.json';
        if (!file_exists($lineIndexPath)) return $cache[$cacheKey] = [];

        $xtPath = $this->findXtFile($dir);
        if (!$xtPath) return $cache[$cacheKey] = [];

        $index = json_decode(file_get_contents($lineIndexPath), true);
        $startOffset = 0;
        $startLine = 0;
        foreach ($index as $il => $off) {
            $il = (int)$il;
            if ($il <= $lineNo) { $startOffset = (int)$off; $startLine = $il; }
        }

        // Same QB method set as SqlPage.vue on the frontend — keep in sync.
        $qbMethods = [
            'select', 'addSelect', 'from', 'where', 'andWhere', 'orWhere',
            'innerJoin', 'leftJoin', 'join', 'rightJoin',
            'orderBy', 'addOrderBy', 'groupBy', 'addGroupBy',
            'having', 'andHaving', 'orHaving',
            'setParameter', 'setParameters', 'setFirstResult', 'setMaxResults',
            'getQuery', 'getResult',
        ];

        $fh = fopen($xtPath, 'rb');
        fseek($fh, $startOffset);
        $ln = $startLine - 1;
        $foundParent = false;
        $parentDepth = $depth ?? 0;
        $chain = [];

        while (($line = fgets($fh, 1_048_576)) !== false) {
            $ln++;
            if (!preg_match(TraceRegex::CallLineStrict->value, $line, $m)) continue;
            $d = (int)(strlen($m[1]) / 2);

            if (!$foundParent) {
                if ($d === $parentDepth && $ln === $lineNo) {
                    $foundParent = true;
                }
                continue;
            }

            if ($d <= $parentDepth) break; // closed the parent's scope

            $body = $m[2];
            $sig = $this->extractSig($body);
            if (!str_contains($sig, 'QueryBuilder->')) continue;

            $method = $this->extractMethodName($sig);
            if ($method === null || !in_array($method, $qbMethods, true)) continue;

            // Each trace line is ONE call — its args are confined to that line's `()`.
            $args = $this->simplifyArgsOfBody($body);
            $chain[] = ['method' => $method, 'args' => $args];
        }
        fclose($fh);

        return $cache[$cacheKey] = $chain;
    }

    /**
     * Extract args (top-level comma list) from a call body of the form
     * "Class->method($a = ..., $b = ...) /file.php:N", paren-aware so nested
     * variadic(args...) and array literals don't get split.
     */
    private function simplifyArgsOfBody(string $body): string
    {
        $open = strpos($body, '(');
        if ($open === false) return '';
        $rest = substr($body, $open + 1);
        // Find the matching ")" by walking balanced parens.
        $depth = 1;
        $closeRel = null;
        $inQ = null;
        $len = strlen($rest);
        for ($i = 0; $i < $len; $i++) {
            $c = $rest[$i];
            if ($inQ) {
                if ($c === $inQ && ($i === 0 || $rest[$i - 1] !== '\\')) $inQ = null;
                continue;
            }
            if ($c === '"' || $c === "'") { $inQ = $c; continue; }
            if ($c === '(' || $c === '[' || $c === '{') $depth++;
            elseif ($c === ')' || $c === ']' || $c === '}') {
                $depth--;
                if ($depth === 0) { $closeRel = $i; break; }
            }
        }
        if ($closeRel === null) return '';
        $argsRaw = substr($rest, 0, $closeRel);
        return $this->simplifyValueList($argsRaw);
    }

    /**
     * Walk a QB body and split it into [(method, args), ...] entries.
     *
     * The body of a fluent chain looks like:
     *   "QueryBuilder->select($select = variadic(0 =>…))->from($from = 'App\\Entity\\User\\…')->where(...)->andWhere(...)->setParameter(...)->getQuery()->getResult()"
     *
     * Strategy:
     *   1. Skip everything up to and including the first "(" — that's the outer method name.
     *   2. Walk balanced parens to find the matching ")" — that's the end of outer args.
     *   3. From there, every "->methodName(" at paren-depth 0 is the next link.
     *
     * Note: object dumps inside args (e.g. `variadic(0 => Doctrine\ORM\QueryBuilder->…())`) contain
     * their own ->method calls at depth 0 that we'd naively pick up. We mitigate by limiting
     * the search to *after* the outer's closing paren — object dumps live INSIDE args.
     */
    /**
     * Simplify a comma-separated list of args (top-level only, paren-aware).
     */
    private function simplifyValueList(string $raw): string
    {
        $parts = [];
        $depth = 0;
        $inQ = null;
        $cur = '';
        $len = strlen($raw);
        for ($i = 0; $i < $len; $i++) {
            $c = $raw[$i];
            if ($inQ) {
                $cur .= $c;
                if ($c === $inQ && ($i === 0 || $raw[$i - 1] !== '\\')) $inQ = null;
                continue;
            }
            if ($c === '"' || $c === "'") { $inQ = $c; $cur .= $c; continue; }
            if ($c === '(' || $c === '[' || $c === '{') $depth++;
            elseif ($c === ')' || $c === ']' || $c === '}') $depth--;
            elseif ($c === ',' && $depth === 0) { $parts[] = trim($cur); $cur = ''; continue; }
            $cur .= $c;
        }
        if (trim($cur) !== '') $parts[] = trim($cur);
        return implode(', ', array_map(fn($a) => $this->simplifyValue($a), $parts));
    }

    private function findXtFile(string $dir): ?string
    {
        foreach (glob($dir . '/*.xt') ?: [] as $f) return $f;
        return null;
    }

    private function extractSig(string $call): string
    {
        $paren = strpos($call, '(');
        return $paren !== false ? trim(substr($call, 0, $paren)) : trim($call);
    }

    private function extractMethodName(string $sig): ?string
    {
        if (!preg_match('/->(\w+)$/', $sig, $m)) return null;
        return $m[1];
    }

    private function simplifyValue(string $val): string
    {
        // Strip "$var = " prefix that xdebug emits for clarity.
        if (preg_match('/^\$?\w+\s*=\s*(.*)$/s', $val, $m)) $val = trim($m[1]);
        if ($val === '') return '';

        // String literal
        if ((str_starts_with($val, "'") && str_ends_with($val, "'")) ||
            (str_starts_with($val, '"') && str_ends_with($val, '"'))) {
            return mb_substr($val, 1, -1);
        }
        // Array literal — inline list
        if (str_starts_with($val, '[') && str_ends_with($val, ']')) {
            $inner = trim(substr($val, 1, -1));
            if ($inner === '') return '[]';
            if (mb_strlen($inner) < 60) return $inner;
            return '[…]';
        }
        // Class instance — short label
        if (preg_match('/^class\s+([\w\\\\]+)/', $val, $m)) {
            $clsParts = explode('\\', $m[1]);
            $cls = end($clsParts);
            return $cls . ' {…}';
        }
        if (preg_match('/^enum\s+([\w\\\\]+)::(\w+)/', $val, $m)) {
            $clsParts = explode('\\', $m[1]);
            $cls = end($clsParts);
            return $cls . '::' . $m[2];
        }
        if (mb_strlen($val) > 60) return mb_substr($val, 0, 60) . '…';
        return $val;
    }
}
