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
     *   first_app_frames: list<array{n:int,time_ms:float,class:?string,method:?string,file:?string,host_path:?string,line:?int}>,
     *   lazy: array{total:int,total_ms:float,by_relation:list<array{entity:string,table:string,count:int,total_ms:float,sample_sql:string}>},
     *   backtrace: array{with_backtrace:int,without_backtrace:int,missing:bool,missing_reason:?string}
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
            'lazy' => $this->summariseLazyLoads($queries),
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
     * Group queries that are Doctrine lazy-loads (relation hydration), so the
     * frontend can show "1 explicit + 5 lazy loads on user_domain" instead of
     * burying the real story under "6 SQL".
     *
     * Heuristic for a lazy load:
     *   - Single-table SELECT (no JOINs)
     *   - The WHERE is a single equality: `WHERE t0.id = ?` or
     *     `WHERE t0.<fk_col> = ?` (Doctrine uses alias `t0` for the target
     *     table in lazy-load queries)
     *   - A `LIMIT 1` is appended on PostgreSQL when the entity has a
     *     composite identifier; we don't require it but tolerate it.
     *
     * This deliberately matches `$em->find($entity, $id)` and
     * `$entity->getRelation()` lazy loads — both produce the same shape.
     * The user-facing N+1 explanation and "fix" hint can then point at
     * the missing join in the parent QueryBuilder.
     *
     * @param list<array<string,mixed>> $queries
     * @return array{total:int,total_ms:float,by_relation:list<array{entity:string,table:string,count:int,total_ms:float,sample_sql:string,sample_n:int,kind:string,trigger_getter:?string,trigger_caller:?string,trigger_file:?string,trigger_line:?int,parent_method:?string,parent_file:?string,parent_line:?int}>}
     */
    private function summariseLazyLoads(array $queries): array
    {
        $byTable = [];
        $lazyCount = 0;
        $lazyMs = 0.0;
        foreach ($queries as $q) {
            if (!self::isLazyLoadSql((string) ($q['sql'] ?? ''))) continue;
            $lazyCount++;
            $ms = (float) ($q['time_ms'] ?? 0.0);
            $lazyMs += $ms;
            $table = self::extractTableFromSql((string) ($q['sql'] ?? ''));
            $key = $table;
            if (!isset($byTable[$key])) {
                $trigger = self::findLazyTrigger((string) ($q['sql'] ?? ''), $q['backtrace'] ?? []);
                // For hydration-triggered loads the user-side frame is the
                // PARENT Repository/Service method that built the query (the
                // one whose QueryBuilder didn't leftJoin the relation).
                $parentFrame = $trigger['user_call'];
                if ($parentFrame === null) {
                    $parentFrame = self::firstAppFrame($q);
                }
                $byTable[$key] = [
                    'entity' => self::guessEntityFromTable($table),
                    'table' => $table,
                    'count' => 0,
                    'total_ms' => 0.0,
                    'sample_sql' => (string) ($q['sql'] ?? ''),
                    'sample_n' => (int) ($q['n'] ?? 0),
                    'kind' => $trigger['kind'],
                    'trigger_getter' => $trigger['getter'],
                    'trigger_caller' => $trigger['user_call']['call'] ?? null,
                    'trigger_file' => $trigger['user_call']['host_path'] ?? $trigger['user_call']['file'] ?? null,
                    'trigger_line' => $trigger['user_call']['line'] ?? null,
                    'parent_method' => $parentFrame ? (($parentFrame['class'] ?? '?') . '->' . ($parentFrame['method'] ?? '?') . '()') : null,
                    'parent_file' => $parentFrame['host_path'] ?? $parentFrame['file'] ?? null,
                    'parent_line' => isset($parentFrame['line']) ? (int) $parentFrame['line'] : null,
                ];
            }
            $byTable[$key]['count']++;
            $byTable[$key]['total_ms'] += $ms;
        }
        usort($byTable, fn($a, $b) => $b['total_ms'] <=> $a['total_ms']);
        return [
            'total' => $lazyCount,
            'total_ms' => $lazyMs,
            'by_relation' => array_values($byTable),
        ];
    }

    /**
     * Walks a backtrace to extract the trigger chain for a lazy-load query.
     *
     * Two flavours of lazy loads exist in real apps, and the fix differs:
     *
     * 1. **getter-triggered** — user code accesses an un-joined relation
     *    AFTER the original query returned. Backtrace looks like:
     *       BasicEntityPersister->load()   ← Doctrine
     *       Proxy\...\__load()
     *       App\Entity\UserDomain->getFacebookPixel()   ← entity getter
     *       App\Service\...\UserDataGetter->doX()        ← user code
     *       App\Controller\...\UserController->getUserData()
     *    Fix: add the relation to the parent's QueryBuilder.
     *
     * 2. **hydration-triggered** — Doctrine's ObjectHydrator encounters the
     *    relation while assembling the original result rows and fires a
     *    sub-select. Backtrace has ObjectHydrator->getEntity() in it but
     *    no App\Entity frame; the user code is the *parent* QueryBuilder
     *    (the `getOneOrNullResult()` call that initiated hydration).
     *    Fix: same — add the relation to the QueryBuilder.
     *
     * We return a `kind` so the UI can label each row correctly.
     *
     * @param list<array<string,mixed>> $bt
     * @return array{kind:string,getter:?string,user_call:?array<string,mixed>}
     */
    private static function findLazyTrigger(string $sql, array $bt): array
    {
        if (!self::isLazyLoadSql($sql) || $bt === []) {
            return ['kind' => 'unknown', 'getter' => null, 'user_call' => null];
        }
        $getter = null;
        $userCaller = null;
        $seenGetter = false;
        $hasObjectHydrator = false;
        foreach ($bt as $f) {
            $cls = (string) ($f['class'] ?? '');
            $file = (string) ($f['file'] ?? '');
            if (str_contains($cls, 'ObjectHydrator')) $hasObjectHydrator = true;
            if ($cls === '' || $file === '') continue;
            if (str_contains($file, '/vendor/')) continue;
            if (str_starts_with($cls, 'Doctrine\\') || str_starts_with($cls, 'Symfony\\') || str_starts_with($cls, 'Sentry\\')) continue;
            if (str_starts_with($cls, 'App\\Entity\\')) {
                if ($getter === null) {
                    $getter = $cls . '->' . ($f['method'] ?? '?') . '()';
                    $seenGetter = true;
                }
                continue;
            }
            if ($seenGetter && str_starts_with($cls, 'App\\') && $userCaller === null) {
                $userCaller = [
                    'call' => $cls . '->' . ($f['method'] ?? '?') . '()',
                    'class' => $cls,
                    'method' => (string) ($f['method'] ?? ''),
                    'file' => $file,
                    'host_path' => $f['host_path'] ?? null,
                    'line' => isset($f['line']) ? (int) $f['line'] : null,
                ];
                break;
            }
        }
        if ($getter !== null) {
            return ['kind' => 'getter', 'getter' => $getter, 'user_call' => $userCaller];
        }
        if ($hasObjectHydrator) {
            return ['kind' => 'hydration', 'getter' => null, 'user_call' => null];
        }
        return ['kind' => 'unknown', 'getter' => null, 'user_call' => null];
    }

    /**
     * True if the SQL is a Doctrine lazy-load — narrow single-table SELECT
     * with EXACTLY one equality on the primary key (or a foreign-key column
     * when Doctrine probes a OneToOne/ManyToOne relation). Pattern is:
     *
     *   SELECT <cols> FROM <table> t0 WHERE t0.<col> = ?
     *
     * No JOINs, no AND, no IN, no LIMIT, no GROUP BY — Doctrine's lazy
     * hydration is always a single-row single-table probe.
     */
    public static function isLazyLoadSql(string $sql): bool
    {
        $s = trim($sql);
        // Strip a trailing LIMIT 1 (PostgreSQL adds it for composite-PK loads).
        $s = preg_replace('/\s+LIMIT\s+\d+\s*$/i', '', $s) ?? $s;
        // Must be: SELECT ... FROM "<table>" <alias> WHERE <alias>.<col> = ?
        // — with NO additional WHERE clauses, NO JOIN, NO IN, NO group by.
        if (!preg_match(
            '/^SELECT\s+[`"A-Za-z0-9_.,\s\\\\]+\s+FROM\s+[`"]?([A-Za-z0-9_]+)[`"]?\s+([A-Za-z0-9_]+)\s+WHERE\s+\2\.[A-Za-z0-9_]+\s*=\s*\?\s*$/i',
            $s
        )) {
            return false;
        }
        // Defensive: no JOIN/UNION/AND/OR/IN/GROUP/sub-select.
        if (preg_match('/\bJOIN\b|\bUNION\b|\bAND\b|\bOR\b|\bIN\s*\)|\bGROUP\s+BY\b|\)\s*SELECT|\bOFFSET\b|\bORDER\s+BY\b/i', $s)) {
            return false;
        }
        return true;
    }

    /**
     * Pull the real table name out of `FROM "<table>" t0` or `FROM <table> t0`.
     */
    public static function extractTableFromSql(string $sql): string
    {
        if (preg_match('/FROM\s+[\`\"]?([A-Za-z0-9_]+)[\`\"]?\s+[A-Za-z0-9_]+\s+WHERE/i', $sql, $m)) {
            return $m[1];
        }
        return 'unknown';
    }

    /**
     * Best-effort: guess the Entity class from the table name (Doctrine naming
     * convention: snake_case → CamelCase + "Entity" suffix). Not used for
     * strict logic — the UI just uses it as a hint label.
     */
    private static function guessEntityFromTable(string $table): string
    {
        $camel = str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $table)));
        return $camel;
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