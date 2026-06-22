<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ProfilerQuery;
use App\Entity\ProfilerSnapshot;
use App\Entity\TraceFile;
use App\Repository\ProfilerQueryRepository;
use App\Repository\ProfilerSnapshotRepository;
use App\Repository\TraceFileRepository;
use App\Service\AppSettings;
use App\Service\Profiler\ProfilerClient;
use App\Service\Profiler\ProfilerConfig;
use App\Service\Profiler\ProfilerException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/profiler')]
class ProfilerController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TraceFileRepository $traceRepo,
        private readonly ProfilerSnapshotRepository $snapRepo,
        private readonly ProfilerQueryRepository $queryRepo,
        private readonly ProfilerConfig $config,
        private readonly ProfilerClient $client,
        private readonly AppSettings $settings,
        /** Host path to the target app's source tree — used to resolve
         *  repo_file paths from lazy-fix snippets back to absolute
         *  filesystem locations the analyser can read. */
        private readonly ?string $targetAppHostDir = null,
    ) {}

    /**
     * The user toggle is a preference — lives in the `app_setting` table,
     * default off, toggled via Settings → Profiler+.
     */
    private function userEnabled(): bool
    {
        return $this->settings->profilerEnabled();
    }

    /**
     * Current profiler+ configuration + status. The settings page uses this
     * to render the "Profiler+" toggle, host, cookies file, etc.
     */
    #[Route('/status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        $enabled = $this->userEnabled();
        $c = $this->config;
        return $this->json([
            'enabled' => $enabled,
            'usable'  => $c->isUsable($enabled),
            'base_url' => $c->baseUrl,
            'src_prefix' => $c->srcPrefix,
            'host_prefix' => $c->hostPrefix,
            'insecure' => $c->insecure,
            'timeout_sec' => $c->timeoutSec,
            'skip_prefixes' => $c->skipPrefixes,
        ]);
    }

    /**
     * Lightweight connectivity check — issues a real GET /_profiler?limit=5.
     */
    #[Route('/ping', methods: ['GET'])]
    public function ping(): JsonResponse
    {
        if (!$this->config->isUsable($this->userEnabled())) {
            return $this->json([
                'ok' => false,
                'reason' => 'profiler+ is disabled or base_url is not configured (check PROFILER_BASE_URL env var)',
            ], 400);
        }
        try {
            $r = $this->client->ping();
            return $this->json($r);
        } catch (ProfilerException $e) {
            return $this->json(['ok' => false, 'error' => $e->getMessage(), 'http_status' => $e->httpStatus], 502);
        }
    }

    /**
     * Persist the Profiler+ on/off toggle. Body: `{ "enabled": true|false }`.
     * Stored in the `app_setting` table, default off.
     */
    #[Route('/toggle', methods: ['POST'])]
    public function toggle(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true) ?: [];
        $enabled = (bool) ($body['enabled'] ?? false);
        $this->settings->setProfilerEnabled($enabled);
        return $this->json(['ok' => true, 'enabled' => $enabled]);
    }

    /**
     * List recent profiler tokens (debug / manual fallback).
     */
    #[Route('/recent', methods: ['GET'])]
    public function recent(Request $request): JsonResponse
    {
        if (!$this->config->isUsable($this->userEnabled())) {
            return $this->json(['ok' => false, 'reason' => 'profiler+ is not configured'], 400);
        }
        $limit = max(1, min(200, (int) $request->query->get('limit', 50)));
        try {
            return $this->json(['ok' => true, 'tokens' => $this->client->listRecent($limit)]);
        } catch (ProfilerException $e) {
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 502);
        }
    }

    /**
     * Try to find a profiler token for this trace file automatically.
     *
     * Strategy: read meta.json, then GET /_profiler?limit=N — that index
     * page already lists (token, method, url, time, status, ip) for every
     * recent request, so we never need a second round-trip per candidate
     * to score them.
     *
     * Scoring (additive, best match wins):
     *   - URL exact match         +100      (strongest signal — same path)
     *   - URL contains target     +50       (looser; e.g. trace has /api/foo and candidate is /api/foo?x=1)
     *   - Method exact match      +30
     *   - IP exact match          +20
     *   - Time within tolerance   +40      (when present in both sides)
     *   - Time beyond tolerance   -1/sec   (penalty to break ties)
     *
     * A "strong" candidate (url+method+ip all match, or url+method match
     * with a small time delta) is auto-accepted. A "weak" candidate
     * (url-only or method-only) is returned with `ok: true` and
     * `confidence: 'low'` so the UI can show a confirm step.
     *
     * Body (optional):
     *   { "tolerance_sec": 5, "limit": 30 }
     */
    #[Route('/find/{fileId}', methods: ['POST'])]
    public function find(int $fileId, Request $request): JsonResponse
    {
        $tf = $this->loadTraceOr404($fileId);
        if ($tf instanceof JsonResponse) return $tf;

        if (!$this->config->isUsable($this->userEnabled())) {
            return $this->json(['ok' => false, 'error' => 'profiler+ is not configured (env vars missing)'], 400);
        }

        $body = json_decode($request->getContent(), true) ?: [];
        $tolerance = (int) ($body['tolerance_sec'] ?? 5);
        $limit = max(1, min(200, (int) ($body['limit'] ?? 30)));

        $meta = $this->readMeta($fileId);
        if ($meta === null) {
            return $this->json(['ok' => false, 'error' => 'meta.json missing for this trace'], 400);
        }
        $req = $meta['request'] ?? null;
        if (!is_array($req) || !isset($req['request_time'])) {
            return $this->json(['ok' => false, 'error' => 'meta.json has no request_time'], 400);
        }

        try {
            $tokens = $this->client->listRecent($limit);
        } catch (ProfilerException $e) {
            return $this->json(['ok' => false, 'error' => 'listRecent failed: ' . $e->getMessage()], 502);
        }

        $targetTime = (float) $req['request_time'];
        $targetMethod = strtoupper((string) ($req['method'] ?? ''));
        // meta.json calls it "uri"; tolerate the legacy "path" too.
        $targetPath = (string) ($req['uri'] ?? $req['path'] ?? '');
        $targetIp = (string) ($req['remote_addr'] ?? '');

        $candidates = [];
        foreach ($tokens as $t) {
            $token = $t['token'] ?? null;
            if (!is_string($token) || $token === '') continue;

            $score = 0;
            $signals = [];

            $cMethod = strtoupper((string) ($t['method'] ?? ''));
            $cUrl = (string) ($t['url'] ?? '');
            $cPath = $cUrl !== '' ? (parse_url($cUrl, PHP_URL_PATH) ?: $cUrl) : '';
            $cIp = (string) ($t['ip'] ?? '');
            $cTime = $t['time'] ?? null;

            // URL scoring
            if ($targetPath !== '' && $cPath !== '') {
                if ($cPath === $targetPath) {
                    $score += 100;
                    $signals[] = 'url';
                } elseif (str_contains($cPath, $targetPath) || str_contains($targetPath, $cPath)) {
                    $score += 50;
                    $signals[] = 'url-partial';
                }
            }
            // Method scoring
            if ($targetMethod !== '' && $cMethod !== '' && $cMethod === $targetMethod) {
                $score += 30;
                $signals[] = 'method';
            }
            // IP scoring
            if ($targetIp !== '' && $cIp !== '' && $cIp === $targetIp) {
                $score += 20;
                $signals[] = 'ip';
            }
            // Time scoring
            $timeDelta = null;
            if (is_numeric($cTime)) {
                $timeDelta = abs((float) $cTime - $targetTime);
                if ($timeDelta <= $tolerance) {
                    $score += 40;
                    $signals[] = 'time';
                } else {
                    $score -= (int) min(50, (int) $timeDelta);
                }
            }

            $candidates[] = [
                'token' => $token,
                'method' => $cMethod !== '' ? $cMethod : null,
                'url' => $cUrl !== '' ? $cUrl : null,
                'path' => $cPath !== '' ? $cPath : null,
                'ip' => $cIp !== '' ? $cIp : null,
                'time' => is_numeric($cTime) ? (int) $cTime : null,
                'time_delta' => $timeDelta,
                'status' => $t['status'] ?? null,
                'score' => $score,
                'signals' => $signals,
            ];
        }

        usort($candidates, fn($a, $b) => $b['score'] <=> $a['score']);
        $best = $candidates[0] ?? null;

        if ($best === null || $best['score'] <= 0) {
            return $this->json([
                'ok' => false,
                'error' => 'no matching profiler token found',
                'candidates' => array_slice($candidates, 0, 5),
            ], 404);
        }

        // Confidence: high if url+method matched (and time was close or ip matched).
        $hasUrl = in_array('url', $best['signals'], true) || in_array('url-partial', $best['signals'], true);
        $hasMethod = in_array('method', $best['signals'], true);
        $hasIp = in_array('ip', $best['signals'], true);
        $hasTime = in_array('time', $best['signals'], true);
        $confidence = match (true) {
            $hasUrl && $hasMethod && ($hasTime || $hasIp) => 'high',
            $hasUrl && $hasMethod                           => 'medium',
            $hasUrl || ($hasMethod && $hasIp)               => 'low',
            default                                         => 'low',
        };

        // Persist.
        $snap = $this->captureFromToken($tf, $best['token'], 'auto', $best['method'], $best['path']);
        return $this->json([
            'ok' => true,
            'token' => $best['token'],
            'method' => $best['method'],
            'path' => $best['path'],
            'score' => $best['score'],
            'confidence' => $confidence,
            'time_delta' => $best['time_delta'],
            'candidates' => array_slice($candidates, 0, 5),
            'snapshot_id' => $snap->getId(),
            'total_queries' => $snap->getTotalQueries(),
            'total_ms' => $snap->getTotalMs(),
        ]);
    }

    /**
     * Manually link a profiler token (or full URL) to a trace file.
     * Body: { "token" | "url": "..." }
     */
    #[Route('/link/{fileId}', methods: ['POST'])]
    public function link(int $fileId, Request $request): JsonResponse
    {
        $tf = $this->loadTraceOr404($fileId);
        if ($tf instanceof JsonResponse) return $tf;

        $body = json_decode($request->getContent(), true) ?: [];
        $raw = trim((string) ($body['token'] ?? $body['url'] ?? ''));
        if ($raw === '') {
            return $this->json(['ok' => false, 'error' => 'token or url required'], 400);
        }
        // Accept full URL — extract token (6+ hex chars).
        if (preg_match('#[0-9a-f]{6,}#', $raw, $m)) {
            $token = $m[0];
        } else {
            $token = $raw;
        }

        if (!$this->config->isUsable($this->userEnabled())) {
            return $this->json(['ok' => false, 'error' => 'profiler+ is not configured'], 400);
        }

        $snap = $this->captureFromToken($tf, $token, 'manual', null, null);
        return $this->json([
            'ok' => true,
            'token' => $token,
            'snapshot_id' => $snap->getId(),
            'total_queries' => $snap->getTotalQueries(),
            'total_ms' => $snap->getTotalMs(),
        ]);
    }

    /**
     * Re-fetch the linked token and overwrite the snapshot.
     */
    #[Route('/refresh/{fileId}', methods: ['POST'])]
    public function refresh(int $fileId): JsonResponse
    {
        $tf = $this->loadTraceOr404($fileId);
        if ($tf instanceof JsonResponse) return $tf;
        if (!$this->config->isUsable($this->userEnabled())) {
            return $this->json(['ok' => false, 'error' => 'profiler+ is not configured'], 400);
        }
        $snap = $this->snapRepo->findForTraceFile($tf);
        if ($snap === null) {
            return $this->json(['ok' => false, 'error' => 'no snapshot linked to this trace'], 404);
        }
        $newSnap = $this->captureFromToken($tf, $snap->getToken(), $snap->getStatus(), $snap->getRequestMethod(), $snap->getRequestPath());
        // Drop the old one (cascade kills ProfilerQuery rows).
        $this->em->remove($snap);
        $this->em->flush();
        return $this->json([
            'ok' => true,
            'token' => $newSnap->getToken(),
            'snapshot_id' => $newSnap->getId(),
            'total_queries' => $newSnap->getTotalQueries(),
            'total_ms' => $newSnap->getTotalMs(),
        ]);
    }

    /**
     * Unlink the snapshot from the trace file (does NOT delete the token on
     * the remote profiler).
     */
    #[Route('/link/{fileId}', methods: ['DELETE'])]
    public function unlink(int $fileId): JsonResponse
    {
        $tf = $this->loadTraceOr404($fileId);
        if ($tf instanceof JsonResponse) return $tf;
        $snap = $this->snapRepo->findForTraceFile($tf);
        if ($snap === null) {
            return $this->json(['ok' => true, 'already_unlinked' => true]);
        }
        $this->em->remove($snap);
        $this->em->flush();
        return $this->json(['ok' => true]);
    }

    /**
     * Get the cached snapshot + analysis for a trace file.
     * Returns { ok, snapshot: {...} | null, analysis: {...} | null, queries: [...] }
     */
    #[Route('/analysis/{fileId}', methods: ['GET'])]
    public function analysis(int $fileId): JsonResponse
    {
        $tf = $this->loadTraceOr404($fileId);
        if ($tf instanceof JsonResponse) return $tf;
        $snap = $this->snapRepo->findForTraceFile($tf);
        if ($snap === null) {
            return $this->json(['ok' => true, 'snapshot' => null, 'analysis' => null, 'queries' => []]);
        }

        $queries = array_map(function (ProfilerQuery $q) {
            return [
                'n' => $q->getN(),
                'sql' => $q->getSql(),
                // Symfony's pre-rendered runnable view (values substituted).
                // Frontend re-formats this for display — no param parsing needed.
                'sql_runnable' => $q->getSqlRunnable(),
                'time' => $q->getTime(),
                'time_ms' => $q->getTimeMs(),
                'params' => $q->getParamsJson() !== null ? json_decode($q->getParamsJson(), true) : null,
                'caller' => [
                    'class' => $q->getCallerClass(),
                    'method' => $q->getCallerMethod(),
                    'file' => $q->getCallerFile(),
                    'host_path' => $q->getCallerHostPath(),
                    'line' => $q->getCallerLine(),
                ],
                'backtrace' => $q->getBacktraceJson() !== null ? json_decode($q->getBacktraceJson(), true) : null,
                // Doctrine lazy-load detection — single-table single-PK SELECTs
                // that fire after the explicit query returned, when code
                // downstream touches an un-joined relation. Marked here so
                // the QbQueryRow can render a 🐢 badge and the user can
                // spot the N+1 in 1 click rather than reading every SQL.
                'lazy' => \App\Service\Profiler\DbAnalyzer::isLazyLoadSql($q->getSql()),
                // For lazy queries: walk the backtrace to find (a) the entity
                // getter that triggered the lazy load (typically the first
                // non-Doctrine frame that lives under App\Entity) and (b) the
                // next non-entity user frame — the actual user code that
                // asked for the relation. This lets the UI show "triggered
                // by UserDomain::getFacebookPixel() in UserDataGetter::...",
                // which is exactly the hint needed to fix the missing join.
                'lazy_trigger' => $this->extractLazyTrigger(
                    $q->getSql(),
                    $q->getBacktraceJson() !== null ? json_decode($q->getBacktraceJson(), true) : null,
                    $q->getCallerClass(),
                    $q->getCallerMethod(),
                ),
            ];
        }, $this->queryRepo->findBySnapshot($snap));

        $analysis = $snap->getAnalysisJson() !== null ? json_decode($snap->getAnalysisJson(), true) : null;

        return $this->json([
            'ok' => true,
            'snapshot' => [
                'id' => $snap->getId(),
                'token' => $snap->getToken(),
                'base_url' => $snap->getBaseUrl(),
                'status' => $snap->getStatus(),
                'error_message' => $snap->getErrorMessage(),
                'request_method' => $snap->getRequestMethod(),
                'request_path' => $snap->getRequestPath(),
                'total_queries' => $snap->getTotalQueries(),
                'total_ms' => $snap->getTotalMs(),
                'captured_at' => $snap->getCapturedAt()->format(\DateTimeInterface::ATOM),
            ],
            'analysis' => $analysis,
            'queries' => $queries,
        ]);
    }

    /**
     * Build a precise leftJoin snippet for a lazy-load row by analysing
     * the target app's entity source code.
     *
     * Body: `{ "kind": "hydration"|"getter", "table": "...",
     *          "parent_class": "UserDomain",   // for hydration: short class name
     *          "fk_column": "affiliate_user_id",  // for hydration (optional)
     *          "trigger_getter": "App\\Entity\\User\\User->getAffiliateAccount"  // for getter
     *        }`
     *
     * Returns the real property name + line in the entity file +
     * joinColumn + the snippet to paste. If the analyser can't resolve
     * the relation, returns a `confidence: 'low'` payload with the best
     * hint we can give (the user can grep manually).
     */
    #[Route('/lazy-fix', methods: ['POST'])]
    public function lazyFix(Request $request, \App\Service\Profiler\TargetAppAnalyzer $analyzer): JsonResponse
    {
        $body = json_decode($request->getContent(), true) ?: [];
        error_log('[lazy-fix] raw body=' . $request->getContent());
        error_log('[lazy-fix] parsed=' . var_export($body, true));
        $kind = (string) ($body['kind'] ?? '');
        $table = (string) ($body['table'] ?? '');
        $parentShort = (string) ($body['parent_class'] ?? '');
        if ($kind === '') {
            return $this->json([
                'ok' => false,
                'error' => 'kind required',
                'debug_body' => $body,
                'debug_kind_var' => $kind,
            ], 400);
        }
        if ($table === '' && $kind !== 'getter') {
            return $this->json(['ok' => false, 'error' => 'table required for kind=hydration'], 400);
        }

        if ($kind === 'hydration' && $parentShort !== '') {
            $candidates = $analyzer->findRelationPropertiesForTable($table);
            if ($candidates === []) {
                $entity = $analyzer->findEntityForTable($table);
                return $this->json([
                    'ok' => true,
                    'confidence' => 'low',
                    'reason' => "no relation property on $parentShort maps to table `$table`",
                    'hint' => $entity ? "entity mapped to `$table` is `{$entity['short']}`" : null,
                ]);
            }
            // Prefer candidates on the parentShort class itself; if
            // none, fall back to first (most likely the inverse side
            // reachable through a joined entity).
            $onParent = array_values(array_filter($candidates, fn($c) => $c['owningClass'] === $parentShort));
            $best = $onParent[0] ?? $candidates[0];

            // Try to discover the real alias used in the parent's
            // QueryBuilder by parsing the Repository file (passed via
            // `repo_file` + `repo_method`). This turns a 50%-accurate
            // alias guess into a 100%-accurate read from the source.
            $alias = $this->aliasGuess($parentShort);
            $aliasSource = 'guessed from class name (no repo_file provided)';
            $parentAlias = null;
            $parentMethod = trim((string) ($body['repo_method'] ?? ''));
            $repoFile = trim((string) ($body['repo_file'] ?? ''));
            error_log('[lazy-fix] body keys: ' . implode(',', array_keys($body)) . ' parentMethod=' . $parentMethod . ' repoFile=' . $repoFile);
            if ($repoFile !== '' && $parentMethod !== '') {
                // Repo files may live under src/Repository/<...>/<Name>.php.
                // Resolve relative path through analyzer's host dir.
                $absRepo = $repoFile;
                if (!str_starts_with($absRepo, '/') && $this->targetAppHostDir !== null) {
                    $absRepo = rtrim($this->targetAppHostDir, '/') . '/' . ltrim($repoFile, '/');
                }
                $repoAnalysis = $analyzer->parseRepositoryMethod($absRepo, $parentMethod);
                if ($repoAnalysis['rootAlias'] !== null) {
                    // Walk leftJoins to find the alias for $best['owningClass'].
                    // If best.owningClass matches a selected alias's
                    // resolved entity, use that alias.
                    foreach ($repoAnalysis['selections'] as $selAlias => $selEntity) {
                        if ($selEntity === $best['owningClass']) {
                            $parentAlias = $selAlias;
                            $alias = $selAlias;
                            $aliasSource = "parsed from $parentMethod (selections)";
                            break;
                        }
                    }
                }
                if ($parentAlias === null && $repoAnalysis['rootAlias'] !== null) {
                    // Fallback: use the root alias if the relation
                    // is on the root entity (e.g. ud.relation).
                    $alias = $repoAnalysis['rootAlias'];
                    $aliasSource = "parsed from $parentMethod (rootAlias)";
                }
            }

            // After parsing: prefer candidates whose owningClass is
            // among the QueryBuilder's select() chain — those are
            // reachable without additional joins. This often changes
            // the recommended property (e.g. 'User.affiliateWithdrawalSettings'
            // instead of the inverse side 'TransferWiseRecipient.affiliateWithdrawalSettings').
            if (isset($repoAnalysis)) {
                $reachable = array_values(array_filter(
                    $candidates,
                    fn($c) => in_array($c['owningClass'], $repoAnalysis['selections'], true)
                ));
                if ($reachable !== [] && ($parentAlias !== null || $best['owningClass'] !== $parentShort)) {
                    $best = $reachable[0];
                    if ($parentAlias === null) {
                        // Re-derive alias from the reachable class
                        foreach ($repoAnalysis['selections'] as $selAlias => $selEntity) {
                            if ($selEntity === $best['owningClass']) {
                                $alias = $selAlias;
                                $aliasSource = "parsed from $parentMethod — preferred reachable via $selAlias ({$best['owningClass']})";
                                break;
                            }
                        }
                    } else {
                        $aliasSource .= " — preferred reachable via " . $best['owningClass'];
                    }
                }
            }

            $best['alias'] = $alias;
            $best['snippet'] = sprintf(
                "->addSelect('%s.%s')\n->leftJoin('%s.%s', '%s')",
                $alias, $best['property'],
                $alias, $best['property'],
                $this->aliasGuess($best['property']),
            );
            $best['file'] = $best['file'] ?? $this->shortToEntityRelPath($best['owningClassFqcn']);
            foreach ($candidates as &$c) {
                $c['alias'] = $alias;
                $c['snippet'] = sprintf(
                    "->addSelect('%s.%s')\n->leftJoin('%s.%s', '%s')",
                    $alias, $c['property'],
                    $alias, $c['property'],
                    $this->aliasGuess($c['property']),
                );
                $c['file'] = $c['file'] ?? $this->shortToEntityRelPath($c['owningClassFqcn']);
            }
            unset($c);
            // Hydration-triggered explanation: Doctrine's ObjectHydrator
            // needed the relation while assembling the original result
            // rows. The fix has to land in the parent QueryBuilder that
            // built the original query — adding the relation there lets
            // Doctrine hydrate it for free instead of firing a sub-select.
            $explanation = sprintf(
                "// Doctrine's ObjectHydrator needed this relation while assembling\n"
                . "// the original result rows (your QueryBuilder didn't leftJoin it).\n"
                . "//\n"
                . "// ->addSelect(): tells Doctrine to SELECT the joined columns.\n"
                . "//   Without it, the JOIN only affects WHERE/ON — the hydrator still\n"
                . "//   triggers a lazy load to fill the entity property.\n"
                . "//\n"
                . "// ->leftJoin() (not innerJoin): keeps rows where the relation is\n"
                . "//   NULL. innerJoin would silently drop them, changing the result.\n"
                . "//\n"
                . "// Folding this relation into the parent QueryBuilder removes the\n"
                . "// round-trip + ORM hydration overhead."
            );
            return $this->json([
                'ok' => true,
                'confidence' => $parentAlias !== null ? 'high' : 'medium',
                'candidates' => $candidates,
                'recommended' => $best,
                'snippet' => $best['snippet'],
                'explanation' => $explanation,
                'file' => $best['file'],
                'line' => $best['line'],
                'property' => $best['property'],
                'alias_source' => $aliasSource,
            ]);
        }

        if ($kind === 'getter') {
            $getter = (string) ($body['trigger_getter'] ?? '');
            if ($getter === '' || !preg_match('/->(\w+)$/', $getter, $m)) {
                return $this->json([
                    'ok' => false,
                    'error' => 'trigger_getter required for kind=getter',
                    'debug_getter' => $getter,
                    'debug_body_keys' => array_keys($body),
                ], 400);
            }
            $prop = $m[1];
            $prop = preg_replace('/^get/', '', $prop);
            $prop = preg_replace('/^is/', '', $prop);
            $prop = preg_replace('/^has/', '', $prop);
            $propCamel = $prop !== '' ? lcfirst($prop) : 'unknown';

            // Trace back to the QueryBuilder that originally fetched
            // the entity. Required params: caller_file, caller_line,
            // entity_var, entity_class.
            $callerFile = trim((string) ($body['caller_file'] ?? ''));
            $callerLine = (int) ($body['caller_line'] ?? 0);
            $entityVar = trim((string) ($body['entity_var'] ?? 'user'));
            $entityClass = trim((string) ($body['entity_class'] ?? 'User'));

            $trace = null;
            if ($callerFile !== '' && $callerLine > 0) {
                $absCaller = $callerFile;
                if (!str_starts_with($absCaller, '/') && $this->targetAppHostDir !== null) {
                    $absCaller = rtrim($this->targetAppHostDir, '/') . '/' . ltrim($callerFile, '/');
                }
                $trace = $analyzer->traceLazyLoadSource($absCaller, $callerLine, $entityVar, $entityClass);
            }

            $alias = $trace['alias'] ?? '<parentAlias>';
            $confidence = ($trace && $trace['alias'] !== null) ? 'high' : 'medium';
            // Getter-triggered explanation: user code accessed the
            // property AFTER the parent query returned. Doctrine fired
            // the lazy load at access time, not during hydration. The
            // fix is the same — fold the relation into the parent
            // QueryBuilder so the data comes back in the original row.
            $explanation = sprintf(
                "// Your code touched \$%s->%s() AFTER the parent query\n"
                . "// returned — Doctrine fired this lazy load at access time.\n"
                . "//\n"
                . "// ->addSelect(): tells Doctrine to SELECT the joined columns.\n"
                . "//   Without it, ->leftJoin() only filters; Doctrine would still\n"
                . "//   emit a lazy load when the property is accessed.\n"
                . "//\n"
                . "// ->leftJoin() (not innerJoin): keeps rows where the relation is\n"
                . "//   NULL. innerJoin would silently drop them, changing the result.\n"
                . "//\n"
                . "// Folding this relation into the parent QueryBuilder removes the\n"
                . "// round-trip + ORM hydration overhead.",
                $entityVar !== '' ? $entityVar : 'user',
                $propCamel,
            );
            return $this->json([
                'ok' => true,
                'confidence' => $confidence,
                'property' => $propCamel,
                'alias' => $alias,
                'snippet' => sprintf(
                    "->addSelect('%s.%s')\n->leftJoin('%s.%s', '%s')",
                    $alias, $propCamel,
                    $alias, $propCamel,
                    $this->aliasGuess($propCamel),
                ),
                'explanation' => $explanation,
                'file' => $trace['file'] ?? null,
                'line' => $trace['line'] ?? null,
                'hint' => $trace['hint'] ?? null,
                'note' => $trace === null
                    ? 'Could not trace the entity variable back to a Repository method — check the caller file manually.'
                    : null,
            ]);
        }

        return $this->json(['ok' => false, 'error' => 'unsupported kind'], 400);
    }

    /**
     * Convert "App\\Entity\\User\\UserDomain" to "src/Entity/User/UserDomain.php"
     * (relative to host path). Returns the original string if it doesn't
     * look like an FQCN.
     */
    private function shortToEntityRelPath(string $fqcn): string
    {
        $p = str_replace('\\', '/', $fqcn);
        if (str_starts_with($p, 'App/Entity/')) {
            return preg_replace('#^App/Entity/#', 'src/Entity/', $p) . '.php';
        }
        return $fqcn;
    }

    /**
     * Heuristic: alias of a class/relation in a QueryBuilder is usually
     * the lowercase first letter of the class name (User→u, Order→o).
     * This is what Doctrine internally uses when the user omits
     * `createQueryBuilder('u')`. Best-effort only — the user has to
     * verify the alias matches their QueryBuilder.
     */
    private function aliasGuess(string $name): string
    {
        $name = ltrim($name, '\\');
        $short = $name !== '' ? substr($name, strrpos($name, '\\') !== false ? strrpos($name, '\\') + 1 : 0) : 'r';
        return $short !== '' ? strtolower($short[0]) : 'r';
    }

    // ─── helpers ─────────────────────────────────────────────────────────

    private function loadTraceOr404(int $fileId): TraceFile|JsonResponse
    {
        $tf = $this->traceRepo->find($fileId);
        if (!$tf) {
            return $this->json(['ok' => false, 'error' => 'trace not found'], 404);
        }
        return $tf;
    }

    /** @return array{request: array, response: array, total_lines: int}|null */
    private function readMeta(int $fileId): ?array
    {
        $candidates = [
            $this->getParameter('traces_dir') . '/' . $fileId . '/meta.json',
            '/app/var/traces/' . $fileId . '/meta.json',
        ];
        foreach ($candidates as $p) {
            if (is_file($p)) {
                $raw = file_get_contents($p);
                $d = $raw !== false ? json_decode($raw, true) : null;
                if (is_array($d)) return $d;
            }
        }
        return null;
    }

    /**
     * Walk the backtrace of a lazy-load query to find the chain that
     * triggered it: the entity getter that Doctrine proxied into, and the
     * user-method that called that getter. Returns null when not a lazy load
     * or when the backtrace is too thin to attribute.
     *
     * The classic chain looks like (top → bottom, where top = closest to SQL):
     *   Doctrine\ORM\Persisters\Entity\BasicEntityPersister->load()
     *   Doctrine\ORM\UnitOfWork->load(...)
     *   Doctrine\ORM\Proxy\...\__load()
     *   App\Entity\UserDomain->getFacebookPixel()    ← entity getter that triggered it
     *   App\Service\Api\UserDataGetter->doSomething() ← user code that called it
     *   App\Controller\Api\Dashboard\UserController->getUserData()
     *
     * We want to surface the getter and the user-method, so the user knows
     * "UserDataGetter reads $userDomain->facebookPixel — that's the relation
     * to add to the findForUserData QueryBuilder".
     *
     * @param list<array<string,mixed>>|null $bt
     * @return array{getter:?array,caller:?array}|null
     */
    private function extractLazyTrigger(string $sql, ?array $bt, ?string $callerClass, ?string $callerMethod): ?array
    {
        if (!\App\Service\Profiler\DbAnalyzer::isLazyLoadSql($sql)) return null;
        if (!is_array($bt) || $bt === []) return null;

        $getter = null;
        $userCaller = null;
        $seenEntityGetter = false;
        foreach ($bt as $f) {
            $cls = (string) ($f['class'] ?? '');
            $file = (string) ($f['file'] ?? '');
            if ($cls === '' || $file === '') continue;
            // Skip vendor / framework entirely.
            if (str_contains($file, '/vendor/')) continue;
            // Skip Doctrine itself (BasicEntityPersister, UnitOfWork, Proxy\__load).
            if (str_starts_with($cls, 'Doctrine\\')) continue;
            // Skip Symfony / Sentry / etc.
            foreach (['Symfony\\', 'Sentry\\'] as $skip) {
                if (str_starts_with($cls, $skip)) continue 2;
            }
            // The first App\Entity frame IS the getter that proxied into Doctrine.
            if (str_starts_with($cls, 'App\\Entity\\')) {
                if ($getter === null) {
                    $getter = [
                        'class' => $cls,
                        'method' => (string) ($f['method'] ?? ''),
                        'file' => $file,
                        'host_path' => $f['host_path'] ?? null,
                        'line' => isset($f['line']) ? (int) $f['line'] : null,
                    ];
                    $seenEntityGetter = true;
                }
                continue;
            }
            // After we've seen the entity getter, the next non-entity App frame
            // is the user code that actually asked for the relation.
            if ($seenEntityGetter && $userCaller === null && str_starts_with($cls, 'App\\')) {
                $userCaller = [
                    'class' => $cls,
                    'method' => (string) ($f['method'] ?? ''),
                    'file' => $file,
                    'host_path' => $f['host_path'] ?? null,
                    'line' => isset($f['line']) ? (int) $f['line'] : null,
                ];
                // Don't break — there might be more frames below, but we want
                // the CLOSEST user frame to the getter, which is what we have.
                break;
            }
        }
        // Fallback: if no entity getter was found, use the saved caller column.
        if ($getter === null && $callerClass !== null && $callerMethod !== null) {
            $getter = ['class' => $callerClass, 'method' => $callerMethod];
        }
        if ($getter === null && $userCaller === null) return null;
        return ['getter' => $getter, 'caller' => $userCaller];
    }

    // ─── helpers ─────────────────────────────────────────────────────────

    /**
     * Fetch the DB panel for $token, persist snapshot + per-query rows, return
     * the entity.
     */
    private function captureFromToken(TraceFile $tf, string $token, string $status, ?string $method, ?string $path): ProfilerSnapshot
    {
        // Delete any previous snapshot for this trace.
        $existing = $this->snapRepo->findForTraceFile($tf);
        if ($existing !== null) {
            $this->em->remove($existing);
            $this->em->flush();
        }

        try {
            $data = $this->client->fetchAndAnalyze($token);
        } catch (ProfilerException $e) {
            $snap = new ProfilerSnapshot();
            $snap->setTraceFile($tf)
                 ->setToken($token)
                 ->setBaseUrl((string) ($this->config->baseUrl ?? ''))
                 ->setStatus('error')
                 ->setErrorMessage($e->getMessage())
                 ->setRequestMethod($method)
                 ->setRequestPath($path);
            $this->em->persist($snap);
            $this->em->flush();
            return $snap;
        }

        $panel = $data['panel'];
        $analysis = $data['analysis'];

        $snap = new ProfilerSnapshot();
        $snap->setTraceFile($tf)
             ->setToken($token)
             ->setBaseUrl((string) ($this->config->baseUrl ?? ''))
             ->setStatus($status)
             ->setRequestMethod($method)
             ->setRequestPath($path)
             ->setTotalQueries((int) ($analysis['total'] ?? 0))
             ->setTotalMs((float) ($analysis['total_ms'] ?? 0.0))
             ->setAnalysisJson(json_encode($analysis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
             ->setRawJson(json_encode($panel, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->em->persist($snap);

        foreach ($panel['queries'] as $q) {
            $bt = $q['backtrace'] ?? [];
            $caller = null;
            $skip = $this->config->skipPrefixes;
            foreach ($bt as $f) {
                $cls = (string) ($f['class'] ?? '');
                $file = (string) ($f['file'] ?? '');
                if ($cls === '' || $file === '') continue;
                if (str_contains($file, '/vendor/')) continue;
                foreach ($skip as $p) {
                    if ($p !== '' && str_starts_with($cls, $p)) continue 2;
                }
                $caller = $f;
                break;
            }

            $entity = new ProfilerQuery();
            $entity->setN((int) ($q['n'] ?? 0))
                   ->setSql((string) ($q['sql'] ?? ''))
                   // Symfony's pre-rendered runnable SQL (with values
                   // substituted, single-line). When present, the frontend
                   // re-formats it for display — the parameter extraction
                   // step is skipped because the values are already inline.
                   ->setSqlRunnable($q['sql_runnable'] ?? null)
                   ->setTime((string) ($q['time'] ?? ''))
                   ->setTimeMs((float) ($q['time_ms'] ?? 0.0))
                   ->setParamsJson(isset($q['params']) ? json_encode($q['params'], JSON_UNESCAPED_UNICODE) : null)
                   ->setCallerClass($caller['class'] ?? null)
                   ->setCallerMethod($caller['method'] ?? null)
                   ->setCallerFile($caller['file'] ?? null)
                   ->setCallerHostPath($caller['host_path'] ?? null)
                   ->setCallerLine(isset($caller['line']) ? (int) $caller['line'] : null)
                   ->setBacktraceJson(json_encode($bt, JSON_UNESCAPED_UNICODE));
            $snap->addQuery($entity);
        }
        $this->em->flush();
        return $snap;
    }
}