<?php

declare(strict_types=1);

namespace App\Service\Profiler;

/**
 * Aggregated analysis of a parsed DB panel.
 *
 * Input: the array returned by DbPanelParser::parse().
 * Output: groups (unique normalised SQL with count + total time),
 *         n_plus_one (groups with count > 1),
 *         callers (top call sites by query count),
 *         slowest (top 10 individual queries),
 *         first_app_frames (first non-framework frame per query).
 */
final class DbAnalyzer
{
    public function __construct(
        public readonly ProfilerConfig $config,
    ) {}

    /**
     * @return array{
     *   url: string,
     *   token: ?string,
     *   total: int,
     *   total_ms: float,
     *   groups: list<array{count:int,total_ms:float,avg_ms:float,query_numbers:list<int>,sample_sql:string,normalised_sql:string}>,
     *   n_plus_one: list<array<string,mixed>>,
     *   callers: list<array{call:string,class:?string,method:?string,file:?string,host_path:?string,line:?int,count:int,query_numbers:list<int>}>,
     *   slowest: list<array{n:int,time:string,time_ms:float,sql:string}>,
     *   first_app_frames: list<array{n:int,time_ms:float,class:?string,method:?string,file:?string,host_path:?string,line:?int}>
     * }
     */
    public function analyze(array $panel): array
    {
        $queries = $panel['queries'] ?? [];

        // --- groups ---------------------------------------------------------
        /** @var array<string, array{count:int,total_ms:float,query_numbers:list<int>,sample_sql:string,normalised_sql:string}> $groups */
        $groups = [];
        foreach ($queries as $q) {
            $key = $this->normaliseSql((string) ($q['sql'] ?? ''));
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'count' => 0,
                    'total_ms' => 0.0,
                    'query_numbers' => [],
                    'sample_sql' => (string) ($q['sql'] ?? ''),
                    'normalised_sql' => $key,
                ];
            }
            $groups[$key]['count']++;
            $groups[$key]['total_ms'] += (float) ($q['time_ms'] ?? 0.0);
            $groups[$key]['query_numbers'][] = (int) ($q['n'] ?? 0);
        }
        foreach ($groups as &$g) {
            $g['avg_ms'] = $g['count'] > 0 ? $g['total_ms'] / $g['count'] : 0.0;
        }
        unset($g);

        // Sort by total_ms desc (the "wasted time" view).
        usort($groups, fn($a, $b) => $b['total_ms'] <=> $a['total_ms']);
        $groups = array_values($groups);

        $nPlusOne = array_values(array_filter($groups, fn($g) => $g['count'] > 1));

        // --- callers --------------------------------------------------------
        /** @var array<string, array{call:string,class:?string,method:?string,file:?string,host_path:?string,line:?int,count:int,query_numbers:list<int>}> $callerMap */
        $callerMap = [];
        foreach ($queries as $q) {
            $frame = $this->firstAppFrame($q);
            if ($frame === null) continue;
            $cls   = $frame['class']   ?? null;
            $meth  = $frame['method']  ?? null;
            $file  = $frame['file']    ?? null;
            $hpath = $frame['host_path'] ?? null;
            $line  = isset($frame['line']) ? (int) $frame['line'] : null;
            $key = $cls . '->' . ($meth ?? '?');
            if (!isset($callerMap[$key])) {
                $callerMap[$key] = [
                    'call' => $key,
                    'class' => $cls,
                    'method' => $meth,
                    'file' => $file,
                    'host_path' => $hpath,
                    'line' => $line,
                    'count' => 0,
                    'query_numbers' => [],
                ];
            }
            $callerMap[$key]['count']++;
            $callerMap[$key]['query_numbers'][] = (int) ($q['n'] ?? 0);
        }
        $callers = array_values($callerMap);
        usort($callers, fn($a, $b) => $b['count'] <=> $a['count']);

        // --- slowest --------------------------------------------------------
        $sorted = $queries;
        usort($sorted, fn($a, $b) => (float)($b['time_ms'] ?? 0) <=> (float)($a['time_ms'] ?? 0));
        $slowest = array_slice(array_map(fn($q) => [
            'n' => (int) ($q['n'] ?? 0),
            'time' => (string) ($q['time'] ?? ''),
            'time_ms' => (float) ($q['time_ms'] ?? 0.0),
            'sql' => (string) ($q['sql'] ?? ''),
        ], $sorted), 0, 10);

        // --- first app frames ----------------------------------------------
        $firstApp = [];
        foreach ($queries as $q) {
            $frame = $this->firstAppFrame($q);
            if ($frame === null) continue;
            $firstApp[] = [
                'n' => (int) ($q['n'] ?? 0),
                'time_ms' => (float) ($q['time_ms'] ?? 0.0),
                'class' => $frame['class'] ?? null,
                'method' => $frame['method'] ?? null,
                'file' => $frame['file'] ?? null,
                'host_path' => $frame['host_path'] ?? null,
                'line' => isset($frame['line']) ? (int) $frame['line'] : null,
            ];
        }
        usort($firstApp, fn($a, $b) => $b['time_ms'] <=> $a['time_ms']);

        // --- token from url ------------------------------------------------
        $token = null;
        if (isset($panel['url']) && preg_match('#/_profiler/([0-9a-f]+)#', $panel['url'], $m)) {
            $token = $m[1];
        }

        return [
            'url' => (string) ($panel['url'] ?? ''),
            'token' => $token,
            'total' => count($queries),
            'total_ms' => array_sum(array_map(fn($q) => (float)($q['time_ms'] ?? 0.0), $queries)),
            'groups' => $groups,
            'n_plus_one' => $nPlusOne,
            'callers' => $callers,
            'slowest' => $slowest,
            'first_app_frames' => $firstApp,
            'backtrace' => $this->summariseBacktrace($queries),
        ];
    }

    /**
     * Report on backtrace presence across the panel.
     *
     * Symfony's DB profiler only attaches full backtraces to query rows when
     * the target app has `profiling_collect_backtrace: '%kernel.debug%'`
     * uncommented in `config/packages/doctrine.yaml`. Without it, every
     * caller/group/n+1 signal is still computed, but only from SQL — the
     * "first app frame" stack is empty and the "View query backtrace"
     * buttons render nothing.
     *
     * The frontend uses `missing_reason` to show a one-line hint to the user
     * pointing at the config switch, so they can fix the target app rather
     * than blame xtrace.
     *
     * @param list<array<string,mixed>> $queries
     * @return array{with_backtrace:int,without_backtrace:int,missing:bool,missing_reason:?string}
     */
    private function summariseBacktrace(array $queries): array
    {
        $with = 0;
        $without = 0;
        foreach ($queries as $q) {
            $bt = $q['backtrace'] ?? [];
            if (is_array($bt) && $bt !== []) {
                $with++;
            } else {
                $without++;
            }
        }
        $missing = $queries !== [] && $with === 0;
        return [
            'with_backtrace' => $with,
            'without_backtrace' => $without,
            'missing' => $missing,
            'missing_reason' => $missing
                ? 'Target app does not collect query backtraces. Uncomment `profiling_collect_backtrace: \'%kernel.debug%\'` in config/packages/doctrine.yaml to enable.'
                : null,
        ];
    }

    /**
     * Strip literals and numbers from a query so structurally identical
     * queries (the N+1 hallmark) collapse into a single key.
     */
    private function normaliseSql(string $sql): string
    {
        // Replace single-quoted strings with ''
        $s = preg_replace("/'[^']*'/", "''", $sql) ?? $sql;
        // Replace standalone integers with N
        $s = preg_replace('/\b\d+\b/', 'N', $s) ?? $s;
        // Replace IN/NOT IN clauses' contents? Keep simple for now.
        return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
    }

    /**
     * First frame that isn't a framework/vendor frame. Used to attribute
     * a query to a call site.
     *
     * @return array<string,mixed>|null
     */
    private function firstAppFrame(array $query): ?array
    {
        $bt = $query['backtrace'] ?? [];
        $skip = $this->config->skipPrefixes;
        foreach ($bt as $f) {
            $cls  = (string) ($f['class'] ?? '');
            $file = (string) ($f['file'] ?? '');
            if ($cls === '' || $file === '') continue;
            if (str_contains($file, '/vendor/')) continue;
            foreach ($skip as $prefix) {
                if ($prefix !== '' && str_starts_with($cls, $prefix)) continue 2;
            }
            return $f;
        }
        // Fallback: first frame that has class+file.
        foreach ($bt as $f) {
            if (!empty($f['class']) && !empty($f['file'])) return $f;
        }
        return null;
    }
}