<?php

namespace App\Controller;

use App\Entity\Annotation;
use App\Entity\FavouritePattern;
use App\Entity\TraceFile;
use App\Message\ParseTraceMessage;
use App\Repository\AnnotationRepository;
use App\Repository\EndpointTimingRepository;
use App\Repository\FavouritePatternRepository;
use App\Repository\TraceFileRepository;
use App\Service\SummaryBuilder;
use App\Service\TraceIndex;
use App\Service\TraceParser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class TraceController extends AbstractController
{
    public function __construct(
        private readonly string $tracesDir,
        private readonly string $tracesSourceDir,
        private readonly EntityManagerInterface $em,
        private readonly TraceFileRepository $traceRepo,
        private readonly AnnotationRepository $annotRepo,
        private readonly FavouritePatternRepository $favRepo,
        private readonly EndpointTimingRepository $timingRepo,
        private readonly TraceIndex $traceIndex,
        private readonly TraceParser $traceParser,
        private readonly SummaryBuilder $summaryBuilder,
        private readonly MessageBusInterface $bus,
    ) {}

    #[Route('/browse', methods: ['GET'])]
    public function browse(Request $request): JsonResponse
    {
        $q = strtolower(trim($request->query->get('q', '')));
        $files = [];
        if (is_dir($this->tracesSourceDir)) {
            $this->collectXtFiles($this->tracesSourceDir, $this->tracesSourceDir, $files);
        }
        if ($q !== '') {
            $files = array_values(array_filter($files, fn($f) => str_contains(strtolower($f['name']), $q)));
        }
        usort($files, fn($a, $b) => $b['size'] <=> $a['size']);
        return $this->json($files);
    }

    private function collectXtFiles(string $baseDir, string $dir, array &$files): void
    {
        $entries = @scandir($dir);
        if (!$entries) return;
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->collectXtFiles($baseDir, $path, $files);
            } elseif (str_ends_with($entry, '.xt') && is_file($path)) {
                $rel = ltrim(substr($path, strlen($baseDir)), '/');
                $files[] = [
                    'name'     => $entry,
                    'rel_path' => $rel,
                    'dir'      => ltrim(substr(dirname($path), strlen($baseDir)), '/'),
                    'path'     => $path,
                    'size'     => filesize($path),
                ];
            }
        }
    }

    #[Route('/open', methods: ['POST'])]
    public function open(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        // Support rel_path (relative path within tracesSourceDir) or legacy filename
        $relPath = $data['rel_path'] ?? $data['filename'] ?? '';

        // Security: resolve and verify the path stays within tracesSourceDir
        $realSource = realpath($this->tracesSourceDir . '/' . $relPath);
        $realBase   = realpath($this->tracesSourceDir);
        if (!$realSource || !$realBase || !str_starts_with($realSource, $realBase . '/')) {
            return $this->json(['error' => 'Invalid file'], 400);
        }

        $displayName = basename($realSource);

        // Check if already imported (same path)
        $existing = $this->traceRepo->findOneBy(['originalName' => $relPath])
            ?? $this->traceRepo->findOneBy(['originalName' => $displayName]);
        if ($existing && $existing->getStatus() === 'ready') {
            return $this->json(['file_id' => $existing->getId(), 'status' => $existing->getStatus()]);
        }

        $traceFile = new TraceFile();
        $traceFile->setOriginalName($relPath);
        $traceFile->setFileHash(md5($realSource));

        $this->em->persist($traceFile);
        $this->em->flush();

        $dir = $this->tracesDir . '/' . $traceFile->getId();
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return $this->json(['error' => 'Cannot create directory'], 500);
        }
        // Symlink instead of copy — no disk duplication
        symlink($realSource, $dir . '/trace.xt');

        $this->bus->dispatch(new ParseTraceMessage($traceFile->getId()));

        return $this->json(['file_id' => $traceFile->getId(), 'status' => 'pending']);
    }

    #[Route('/upload', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        $file = $request->files->get('file');
        if (!$file) {
            return $this->json(['error' => 'No file'], 400);
        }

        $traceFile = new TraceFile();
        $traceFile->setOriginalName($file->getClientOriginalName());
        $traceFile->setFileHash(md5_file($file->getPathname()));

        $this->em->persist($traceFile);
        $this->em->flush();

        $dir = $this->tracesDir . '/' . $traceFile->getId();
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return $this->json(['error' => 'Cannot create directory'], 500);
        }
        $file->move($dir, 'trace.xt');

        $this->bus->dispatch(new ParseTraceMessage($traceFile->getId()));

        return $this->json([
            'file_id' => $traceFile->getId(),
            'status'  => $traceFile->getStatus(),
        ]);
    }

    #[Route('/status/{id}', methods: ['GET'])]
    public function status(int $id): JsonResponse
    {
        $traceFile = $this->traceRepo->find($id);
        if (!$traceFile) return $this->json(['error' => 'Not found'], 404);

        $progress = $traceFile->getProgress();
        $status = $traceFile->getStatus();
        if ($status === 'parsing' || $status === 'pending') {
            $progressFile = sys_get_temp_dir() . '/parse-progress-' . $traceFile->getId() . '.txt';
            $raw = @file_get_contents($progressFile);
            if ($raw !== false && is_numeric(trim($raw))) {
                $progress = (int)trim($raw);
                $status = 'parsing';
            }
        }

        return $this->json([
            'file_id'  => $traceFile->getId(),
            'name'     => $traceFile->getOriginalName(),
            'status'   => $status,
            'progress' => $progress,
            'error'    => $traceFile->getErrorMessage(),
        ]);
    }

    #[Route('/files', methods: ['GET'])]
    public function files(): JsonResponse
    {
        $files = $this->traceRepo->findBy([], ['createdAt' => 'DESC']);
        return $this->json(array_map(fn($f) => [
            'file_id'  => $f->getId(),
            'name'     => $f->getOriginalName(),
            'status'   => $f->getStatus(),
            'progress' => $f->getProgress(),
            'created'  => $f->getCreatedAt()->format('Y-m-d H:i'),
        ], $files));
    }

    #[Route('/skeleton/{id}', methods: ['GET'])]
    public function skeleton(int $id): JsonResponse
    {
        $traceFile = $this->traceRepo->find($id);
        if (!$traceFile || $traceFile->getStatus() !== 'ready') {
            return $this->json(['error' => 'Not ready'], 404);
        }

        $path = $this->tracesDir . '/' . $id . '/skeleton.json';
        return new JsonResponse(file_get_contents($path), 200, [], true);
    }

    #[Route('/reparse/{id}', methods: ['POST'])]
    public function reparse(int $id): JsonResponse
    {
        $traceFile = $this->traceRepo->find($id);
        if (!$traceFile) return $this->json(['error' => 'Not found'], 404);

        // Signal any running parser for this file to stop
        touch(sys_get_temp_dir() . '/parse-cancel-' . $id . '.txt');
        $traceFile->setStatus('pending')->setProgress(0);
        $this->em->flush();
        // Remove any queued duplicates for this file before dispatching
        $this->em->getConnection()->executeStatement(
            "DELETE FROM messenger_messages WHERE body LIKE :pattern",
            ['pattern' => '%traceFileId%i:' . $id . '%']
        );
        $this->bus->dispatch(new ParseTraceMessage($traceFile->getId()));

        return $this->json(['ok' => true, 'file_id' => $id]);
    }

    #[Route('/toc/{id}', methods: ['GET'])]
    public function toc(int $id, Request $request): JsonResponse
    {
        $traceFile = $this->traceRepo->find($id);
        if (!$traceFile || $traceFile->getStatus() !== 'ready') {
            return $this->json(['error' => 'Not ready'], 404);
        }

        $path = $this->tracesDir . '/' . $id . '/toc.json';
        if (!file_exists($path)) {
            return $this->json([]);
        }

        // ETag + 304 for repeat calls — toc.json is immutable after parse.
        $mtime = filemtime($path);
        $response = new JsonResponse();
        $response->setEtag(base64_encode(pack('N', crc32(file_get_contents($path, false, null, 0, 8192)))));
        $response->setLastModified((new \DateTime())->setTimestamp($mtime));
        $response->setPublic();
        if ($response->isNotModified($request)) {
            return $response;
        }

        // Strip app_calls trees from the TOC response — they can be 100k+ nodes (38MB+).
        // Frontend loads them lazily via /api/app-calls/{id}/{event_idx}.
        $toc = json_decode(file_get_contents($path), true);
        foreach ($toc as &$entry) {
            if (isset($entry['app_calls'])) {
                $entry['app_calls_count'] = $this->countAppCallNodes($entry['app_calls']);
                $entry['app_calls'] = null; // signal to frontend: use lazy load
            }
            // Also strip app_calls from nested controller children
            if (!empty($entry['children'])) {
                foreach ($entry['children'] as &$child) {
                    if (isset($child['app_calls'])) {
                        $child['app_calls_count'] = $this->countAppCallNodes($child['app_calls']);
                        $child['app_calls'] = null;
                    }
                }
                unset($child);
            }
        }
        unset($entry);

        $response->setData($toc);
        return $response;
    }

    private function countAppCallNodes(array $nodes): int
    {
        $total = 0;
        foreach ($nodes as $node) {
            $total++;
            if (!empty($node['children'])) {
                $total += $this->countAppCallNodes($node['children']);
            }
        }
        return $total;
    }

    #[Route('/app-calls/{id}/{eventIdx}', methods: ['GET'])]
    public function appCalls(int $id, int $eventIdx): JsonResponse
    {
        $traceFile = $this->traceRepo->find($id);
        if (!$traceFile || $traceFile->getStatus() !== 'ready') {
            return $this->json(['error' => 'Not ready'], 404);
        }

        // Fast path: per-event cache file (built by TraceParser::writeAppCallsCache).
        $cacheFile = $this->tracesDir . '/' . $id . '/app_calls/' . $eventIdx . '.json';
        if (file_exists($cacheFile)) {
            $response = new JsonResponse();
            $response->setEtag(base64_encode(pack('N', crc32(file_get_contents($cacheFile, false, null, 0, 8192)))));
            $response->setLastModified((new \DateTime())->setTimestamp(filemtime($cacheFile)));
            $response->setPublic();
            $req = Request::createFromGlobals();
            if ($response->isNotModified($req)) {
                return $response;
            }
            $response->setContent(file_get_contents($cacheFile));
            return $response;
        }

        // Fallback for traces parsed before the cache was added — read whole toc.json.
        // Supports nested "3.2" notation by recursively walking.
        $path = $this->tracesDir . '/' . $id . '/toc.json';
        if (!file_exists($path)) {
            return $this->json([]);
        }
        $toc = json_decode(file_get_contents($path), true);
        $entry = $this->findTocEntryByIdx($toc, $eventIdx);
        if ($entry === null) {
            return $this->json(['error' => 'Event not found'], 404);
        }
        return $this->json($entry['app_calls'] ?? []);
    }

    private function findTocEntryByIdx(array $entries, int|string $idx): ?array
    {
        if (is_int($idx)) {
            return $entries[$idx] ?? null;
        }
        // "3.2" — walk through nested children
        $parts = explode('.', (string)$idx);
        $current = $entries[(int)$parts[0]] ?? null;
        for ($i = 1; $i < count($parts) && $current !== null; $i++) {
            $current = $current['children'][(int)$parts[$i]] ?? null;
        }
        return $current;
    }

    /**
     * Resolve a method's declaration site in the analysed project via PSR-4.
     * Returns [absoluteFilePath, startLine, endLine] or null if not found.
     */
    private function resolveMethodInProject(string $class, string $method): ?array
    {
        $projectDir = getenv('SOURCE_CONTAINER_DIR');
        if (!$projectDir || !is_dir($projectDir)) return null;

        // PSR-4: App\Foo\Bar → src/Foo/Bar.php
        $relParts = explode('\\', $class);
        // strip the leading vendor name(s) until we find "App\\" or a known root
        // For systeme.io monolith: namespace App\… → src/…
        // Heuristic: try the last N namespace segments as relative path.
        $escaped = preg_quote($method, '/');

        // Try common PSR-4 roots: src/, app/, lib/. For each, scan for the
        // expected class file by stripping common namespace prefixes.
        $roots = ['src', 'app', 'lib', 'classes'];
        $candidates = [];
        foreach ($roots as $root) {
            $base = $projectDir . '/' . $root;
            if (!is_dir($base)) continue;
            // Try the full FQCN path (e.g. App\Repository\User\Foo → src/Repository/User/Foo.php)
            $tail = preg_replace('/^App\\\\?/', '', $class);
            $candidates[] = $base . '/' . str_replace('\\', '/', $tail) . '.php';
            // Also try one level deeper: src/Entity/User/Foo.php from App\Entity\User\Foo
            // (rare; safety net)
        }

        // Also try the class file at its fully qualified path (no prefix strip)
        // in case the project uses a different convention.
        $candidates[] = $projectDir . '/' . str_replace('\\', '/', $class) . '.php';

        foreach ($candidates as $file) {
            if (!is_file($file)) continue;
            $lines = file($file, FILE_IGNORE_NEW_LINES);
            if ($lines === false) continue;
            $startLine = null;
            $depth = 0;
            $inMethod = false;
            for ($i = 0; $i < count($lines); $i++) {
                $line = $lines[$i];
                if ($startLine === null) {
                    if (preg_match('/^\s*(public|protected|private|static|abstract|final|\s)*function\s+' . $escaped . '\s*\(/', $line)) {
                        $startLine = $i + 1;
                        $depth = 0;
                        $inMethod = true;
                    }
                    continue;
                }
                // Walk braces inside the method to find the matching close
                $depth += substr_count($line, '{') - substr_count($line, '}');
                if ($inMethod && $depth <= 0) {
                    return [$file, $startLine, $i + 1];
                }
            }
        }
        return null;
    }

    #[Route('/meta/{id}', methods: ['GET'])]
    public function meta(int $id, Request $request): JsonResponse
    {
        $traceFile = $this->traceRepo->find($id);
        if (!$traceFile || $traceFile->getStatus() !== 'ready') {
            return $this->json(['error' => 'Not ready'], 404);
        }

        $metaPath = $this->tracesDir . '/' . $id . '/meta.json';
        if (file_exists($metaPath)) {
            $response = new JsonResponse();
            $response->setEtag(base64_encode(pack('N', crc32(file_get_contents($metaPath, false, null, 0, 8192)))));
            $response->setLastModified((new \DateTime())->setTimestamp(filemtime($metaPath)));
            $response->setPublic();
            if ($response->isNotModified($request)) {
                return $response;
            }
            $response->setContent(file_get_contents($metaPath));
            return $response;
        }

        // Fallback: derive total_lines from last key in line_index
        $indexPath = $this->tracesDir . '/' . $id . '/line_index.json';
        $index = json_decode(file_get_contents($indexPath), true);
        $lastIndexedLine = (int)array_key_last($index);
        return $this->json(['total_lines' => $lastIndexedLine]);
    }

    #[Route('/timings/{id}', methods: ['GET'])]
    public function timings(int $id, Request $request): JsonResponse
    {
        $traceFile = $this->traceRepo->find($id);
        if (!$traceFile) return $this->json(['error' => 'Not found'], 404);

        $limit = min(200, max(1, (int)$request->query->get('limit', 50)));
        $timings = $this->timingRepo->findByTraceName($traceFile->getOriginalName(), $limit);

        return $this->json(array_map(fn($t) => [
            'id'              => $t->getId(),
            'endpoint_url'    => $t->getEndpointUrl(),
            'endpoint_method' => $t->getEndpointMethod(),
            'duration_ms'     => $t->getDurationMs(),
            'created'         => $t->getCreatedAt()->format('H:i:s'),
        ], $timings));
    }

    #[Route('/reparse-sql/{id}', methods: ['POST'])]
    public function reparseSql(int $id): JsonResponse
    {
        $traceFile = $this->traceRepo->find($id);
        if (!$traceFile || $traceFile->getStatus() !== 'ready') {
            return $this->json(['error' => 'Not ready'], 404);
        }

        $dir        = $this->tracesDir . '/' . $id;
        $xtFilePath = $this->findXtFile($dir);
        if (!$xtFilePath) {
            return $this->json(['error' => 'Trace file not found'], 404);
        }

        $tocFile = $dir . '/toc.json';
        $toc     = file_exists($tocFile) ? (json_decode(file_get_contents($tocFile), true) ?? []) : [];
        $queries = $this->traceParser->extractSqlQueriesPublic($xtFilePath, $toc);
        file_put_contents($dir . '/sql.json', json_encode($queries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $this->json(['ok' => true, 'count' => count($queries)]);
    }

    /**
     * Build a single AI-friendly markdown summary of the trace.
     *
     * Query params:
     *   - sections   = comma-separated subset of context,toc,sql,annotations,timings
     *                   (default = all)
     *   - max_queries = cap on SQL queries included, oldest dropped first (default 200)
     *   - include_qb  = "0" to skip QueryBuilder chain extraction (default 1)
     *
     * Returns { text, stats: {events, listeners, sql_queries, sql_n_plus_1,
     *                          annotations, timings, favourites, sections_included,
     *                          chars}, truncated: bool }
     */
    #[Route('/summary/{id}', methods: ['GET'])]
    public function summary(int $id, Request $request): JsonResponse
    {
        $traceFile = $this->traceRepo->find($id);
        if (!$traceFile || $traceFile->getStatus() !== 'ready') {
            return $this->json(['error' => 'Not ready'], 404);
        }

        $allSections = ['context', 'toc', 'sql', 'annotations', 'timings'];
        $sectionsParam = $request->query->get('sections', '');
        if ($sectionsParam === '') {
            $sections = $allSections;
        } else {
            $requested = array_filter(array_map('trim', explode(',', $sectionsParam)));
            $sections = array_values(array_intersect($requested, $allSections));
            if (!$sections) {
                return $this->json(['error' => 'No valid sections requested. Allowed: ' . implode(',', $allSections)], 400);
            }
        }

        $maxQueries = (int)$request->query->get('max_queries', 200);
        $maxQueries = max(1, min(2000, $maxQueries));

        $includeQb = $request->query->get('include_qb', '1') !== '0';

        $result = $this->summaryBuilder->build(
            $traceFile,
            $sections,
            $maxQueries,
            $includeQb,
        );

        return $this->json($result);
    }

    #[Route('/sql/{id}', methods: ['GET'])]
    public function sql(int $id): JsonResponse
    {
        $traceFile = $this->traceRepo->find($id);
        if (!$traceFile || $traceFile->getStatus() !== 'ready') {
            return $this->json(['error' => 'Not ready'], 404);
        }

        $path = $this->tracesDir . '/' . $id . '/sql.json';
        if (!file_exists($path)) {
            return $this->json(['error' => 'sql.json not found — reparse the file'], 404);
        }

        return new JsonResponse(file_get_contents($path), 200, [], true);
    }

    #[Route('/lines/{id}', methods: ['GET'])]
    public function lines(int $id, Request $request): JsonResponse
    {
        $traceFile = $this->traceRepo->find($id);
        if (!$traceFile || $traceFile->getStatus() !== 'ready') {
            return $this->json(['error' => 'Not ready'], 404);
        }

        $from = max(1, (int)$request->query->get('from', 1));
        $to   = min($from + 499, (int)$request->query->get('to', $from + 199));

        $xtPath = $this->tracesDir . '/' . $id . '/trace.xt';
        $lines = $this->traceIndex->getLines($id, $xtPath, $from, $to);

        return $this->json($lines);
    }

    #[Route('/children/{id}', methods: ['GET'])]
    public function children(int $id, Request $request): JsonResponse
    {
        $traceFile = $this->traceRepo->find($id);
        if (!$traceFile || $traceFile->getStatus() !== 'ready') {
            return $this->json(['error' => 'Not ready'], 404);
        }

        $lineNo    = (int)$request->query->get('line_no', 0);
        $callDepth = (int)$request->query->get('depth', 0);
        $raw       = $request->query->getBoolean('raw', false);
        $filter    = !$raw;

        if ($lineNo <= 0) return $this->json(['error' => 'line_no required'], 400);

        // Disk cache for filtered (non-raw) results — trace files never change after parsing
        if ($filter) {
            $cacheDir  = $this->tracesDir . '/' . $id . '/children_cache';
            $cacheFile = $cacheDir . '/' . $lineNo . '_' . $callDepth . '.json';
            if (file_exists($cacheFile)) {
                return new JsonResponse(file_get_contents($cacheFile), 200, [], true);
            }
        }

        $xtPath = $this->tracesDir . '/' . $id . '/trace.xt';
        $result = $this->traceIndex->getChildren($id, $xtPath, $lineNo, $callDepth, $filter);
        $children = $result['children'];
        $parentReturn = $result['parent_return'];

        $patterns = array_map(
            fn($f) => ['pattern' => $f->getPattern(), 'label' => $f->getLabel()],
            $this->favRepo->findAll()
        );
        if ($patterns) {
            foreach ($children as &$child) {
                $text = $child['sig'] . ' ' . implode(' ', $child['args'] ?? []);
                $matches = [];
                foreach ($patterns as $p) {
                    if ($p['pattern'] !== '' && str_contains($text, $p['pattern'])) {
                        $matches[] = $p;
                    }
                }
                $child['fav_matches'] = $matches;
            }
            unset($child);
        }

        $payload = json_encode(['children' => $children, 'parent_return' => $parentReturn, 'raw_count' => $result['raw_count'] ?? 0]);

        if ($filter) {
            if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
            @file_put_contents($cacheFile, $payload);
        }

        return new JsonResponse($payload, 200, [], true);
    }

    #[Route('/schema/{id}', methods: ['POST'])]
    public function schema(int $id, Request $request): JsonResponse
    {
        $traceFile = $this->traceRepo->find($id);
        if (!$traceFile || $traceFile->getStatus() !== 'ready') {
            return $this->json(['error' => 'Not ready'], 404);
        }

        $data  = json_decode($request->getContent(), true);
        $items = $data['items'] ?? [];
        if (!$items) return $this->json([]);

        $xtPath = $this->tracesDir . '/' . $id . '/trace.xt';
        $tree   = $this->traceIndex->buildSchema($id, $xtPath, $items);

        return $this->json($tree);
    }

    #[Route('/favourites-scan/{id}', methods: ['GET'])]
    public function favouritesScan(int $id, Request $request): JsonResponse
    {
        $traceFile = $this->traceRepo->find($id);
        if (!$traceFile || $traceFile->getStatus() !== 'ready') {
            return $this->json(['error' => 'Not ready'], 404);
        }

        $patterns = array_map(
            fn($f) => ['pattern' => $f->getPattern(), 'label' => $f->getLabel()],
            $this->favRepo->findAll()
        );
        if (!$patterns) return $this->json([]);

        $cacheKey = $this->favScanCacheKey($patterns);
        $cacheFile = $this->tracesDir . '/' . $id . '/fav_scan_cache/' . $cacheKey . '.json';

        // Cache hit: return immediately. Status check is here (not just file_exists)
        // because the worker might still be writing when the frontend polls.
        if (file_exists($cacheFile) && filesize($cacheFile) > 2) {
            return $this->favScanJsonResponse($cacheFile, $request);
        }

        // Cache miss: dispatch background job. Returns HTTP 202 with status=scanning.
        // Frontend polls /api/favourites-scan/{id}/status until ready.
        $this->bus->dispatch(new \App\Message\ScanFavouritesMessage($id, $cacheKey));
        return $this->json(['status' => 'scanning', 'cache_key' => $cacheKey], 202);
    }

    #[Route('/favourites-scan/{id}/status', methods: ['GET'])]
    public function favouritesScanStatus(int $id, Request $request): JsonResponse
    {
        $traceFile = $this->traceRepo->find($id);
        if (!$traceFile || $traceFile->getStatus() !== 'ready') {
            return $this->json(['error' => 'Not ready'], 404);
        }

        $patterns = array_map(
            fn($f) => ['pattern' => $f->getPattern(), 'label' => $f->getLabel()],
            $this->favRepo->findAll()
        );
        if (!$patterns) {
            return $this->json(['status' => 'ready', 'result' => new \stdClass()]);
        }

        $cacheKey = $this->favScanCacheKey($patterns);
        $cacheFile = $this->tracesDir . '/' . $id . '/fav_scan_cache/' . $cacheKey . '.json';

        if (file_exists($cacheFile) && filesize($cacheFile) > 2) {
            return $this->favScanJsonResponse($cacheFile, $request);
        }

        // No dispatch here — status endpoint is poll-only. If the original dispatch
        // was lost (e.g. worker restart between dispatch and consume), the user can
        // re-trigger by calling /api/favourites-scan/{id} again.
        return $this->json(['status' => 'scanning', 'cache_key' => $cacheKey], 202);
    }

    private function favScanCacheKey(array $patterns): string
    {
        $keys = array_column($patterns, 'pattern');
        sort($keys);
        return md5(implode("\n", $keys));
    }

    private function favScanJsonResponse(string $cacheFile, Request $request): JsonResponse
    {
        $raw = file_get_contents($cacheFile);
        $mtime = filemtime($cacheFile);
        $response = new JsonResponse();
        $response->setEtag(base64_encode(pack('N', crc32(substr($raw, 0, 8192)))));
        $response->setLastModified((new \DateTime())->setTimestamp($mtime));
        $response->setPublic();
        if ($response->isNotModified($request)) {
            return $response;
        }
        $response->setContent($raw);
        return $response;
    }

    #[Route('/path/{id}', methods: ['GET'])]
    public function path(int $id, Request $request): JsonResponse
    {
        $traceFile = $this->traceRepo->find($id);
        if (!$traceFile || $traceFile->getStatus() !== 'ready') {
            return $this->json(['error' => 'Not ready'], 404);
        }
        $lineNo = (int)$request->query->get('line_no', 0);
        if ($lineNo <= 0) return $this->json(['error' => 'line_no required'], 400);
        $fromLine = (int)$request->query->get('from_line', 0);

        $xtPath = $this->tracesDir . '/' . $id . '/trace.xt';
        return $this->json($this->traceIndex->getAncestorPath($id, $xtPath, $lineNo, $fromLine));
    }

    #[Route('/object/{id}', methods: ['GET'])]
    public function object(int $id, Request $request): JsonResponse
    {
        $traceFile = $this->traceRepo->find($id);
        if (!$traceFile || $traceFile->getStatus() !== 'ready') {
            return $this->json(['error' => 'Not ready'], 404);
        }

        $lineNo  = (int)$request->query->get('line_no', 0);
        $argIdx  = (int)$request->query->get('arg_idx', 0);

        if ($lineNo <= 0) return $this->json(['error' => 'line_no required'], 400);

        $xtPath = $this->tracesDir . '/' . $id . '/trace.xt';
        $result = $this->traceIndex->getObjectArg($id, $xtPath, $lineNo, $argIdx);

        if ($result === null) return $this->json(['error' => 'Not an object or not found'], 404);

        return $this->json($result);
    }

    #[Route('/find-object/{id}', methods: ['GET'])]
    public function findObject(int $id, Request $request): JsonResponse
    {
        $traceFile = $this->traceRepo->find($id);
        if (!$traceFile || $traceFile->getStatus() !== 'ready') {
            return $this->json(['error' => 'Not ready'], 404);
        }
        $lineNo    = (int)$request->query->get('line_no', 0);
        $className = trim($request->query->get('class', ''));
        if ($lineNo <= 0 || $className === '') return $this->json(['error' => 'line_no and class required'], 400);

        $xtPath = $this->tracesDir . '/' . $id . '/trace.xt';
        $result = $this->traceIndex->findObjectByClass($id, $xtPath, $lineNo, $className);
        if ($result === null) return $this->json(['error' => 'Not found'], 404);
        return $this->json($result);
    }

    #[Route('/array/{id}', methods: ['GET'])]
    public function array(int $id, Request $request): JsonResponse
    {
        $traceFile = $this->traceRepo->find($id);
        if (!$traceFile || $traceFile->getStatus() !== 'ready') {
            return $this->json(['error' => 'Not ready'], 404);
        }
        $lineNo = (int)$request->query->get('line_no', 0);
        $argIdx = (int)$request->query->get('arg_idx', 0);
        if ($lineNo <= 0) return $this->json(['error' => 'line_no required'], 400);

        $xtPath = $this->tracesDir . '/' . $id . '/trace.xt';
        $result = $this->traceIndex->getArrayArg($id, $xtPath, $lineNo, $argIdx);
        if ($result === null) return $this->json(['error' => 'Not an array or not found'], 404);
        return $this->json($result);
    }

    #[Route('/expand-item/{id}', methods: ['POST'])]
    public function expandItem(int $id, Request $request): JsonResponse
    {
        $traceFile = $this->traceRepo->find($id);
        if (!$traceFile || $traceFile->getStatus() !== 'ready') {
            return $this->json(['error' => 'Not ready'], 404);
        }
        $raw = $request->toArray()['raw'] ?? null;
        if (!$raw) return $this->json(['error' => 'raw required'], 400);
        $result = $this->traceIndex->expandItem($raw);
        if ($result === null) return $this->json(['error' => 'Not expandable'], 404);
        return $this->json($result);
    }

    #[Route('/var-context/{id}', methods: ['GET'])]
    public function varContext(int $id, Request $request): JsonResponse
    {
        $traceFile = $this->traceRepo->find($id);
        if (!$traceFile || $traceFile->getStatus() !== 'ready') {
            return $this->json(['error' => 'Not ready'], 404);
        }

        $lineNo    = (int)$request->query->get('line_no', 0);
        $callDepth = (int)$request->query->get('depth', 0);

        if ($lineNo <= 0) return $this->json(['error' => 'line_no required'], 400);

        $xtPath = $this->tracesDir . '/' . $id . '/trace.xt';
        $result = $this->traceIndex->getVarContext($id, $xtPath, $lineNo, $callDepth);

        return $this->json($result);
    }

    #[Route('/search/{id}', methods: ['GET'])]
    public function search(int $id, Request $request): JsonResponse
    {
        $traceFile = $this->traceRepo->find($id);
        if (!$traceFile || $traceFile->getStatus() !== 'ready') {
            return $this->json(['error' => 'Not ready'], 404);
        }

        $q = trim($request->query->get('q', ''));
        if (strlen($q) < 2) return $this->json([]);

        $xtPath = $this->tracesDir . '/' . $id . '/trace.xt';
        $results = $this->traceIndex->search($id, $xtPath, $q);

        return $this->json($results);
    }

    #[Route('/favourites', methods: ['GET'])]
    public function getFavourites(): JsonResponse
    {
        $favs = $this->favRepo->findBy([], ['createdAt' => 'DESC']);
        return $this->json(array_map(fn($f) => [
            'id'      => $f->getId(),
            'pattern' => $f->getPattern(),
            'label'   => $f->getLabel(),
        ], $favs));
    }

    #[Route('/favourites', methods: ['POST'])]
    public function createFavourite(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $pattern = trim($data['pattern'] ?? '');
        if ($pattern === '') return $this->json(['error' => 'pattern required'], 400);

        // Deduplicate
        $existing = $this->favRepo->findOneBy(['pattern' => $pattern]);
        if ($existing) return $this->json(['id' => $existing->getId(), 'existed' => true]);

        $fav = new FavouritePattern();
        $fav->setPattern($pattern)->setLabel($data['label'] ?? null);
        $this->em->persist($fav);
        $this->em->flush();

        // Invalidate fav_scan_cache for all parsed traces — new pattern must be matched
        foreach ($this->traceRepo->findAll() as $tf) {
            $this->traceIndex->invalidateFavouritesCache($tf->getId());
        }

        return $this->json(['id' => $fav->getId()], 201);
    }

    #[Route('/favourites/{favId}', methods: ['DELETE'])]
    public function deleteFavourite(int $favId): JsonResponse
    {
        $fav = $this->favRepo->find($favId);
        if (!$fav) return $this->json(['error' => 'Not found'], 404);
        $this->em->remove($fav);
        $this->em->flush();

        foreach ($this->traceRepo->findAll() as $tf) {
            $this->traceIndex->invalidateFavouritesCache($tf->getId());
        }
        return $this->json(['ok' => true]);
    }

    #[Route('/annotations/{id}', methods: ['GET'])]
    public function getAnnotations(int $id): JsonResponse
    {
        $annotations = $this->annotRepo->findByTraceFile($id);
        return $this->json(array_map(fn($a) => [
            'id'      => $a->getId(),
            'line_no' => $a->getLineNo(),
            'text'    => $a->getText(),
            'created' => $a->getCreatedAt()->format('Y-m-d H:i'),
        ], $annotations));
    }

    #[Route('/annotations/{id}', methods: ['POST'])]
    public function createAnnotation(int $id, Request $request): JsonResponse
    {
        $traceFile = $this->traceRepo->find($id);
        if (!$traceFile) return $this->json(['error' => 'Not found'], 404);
        assert($traceFile instanceof TraceFile);

        $data = json_decode($request->getContent(), true);
        if (empty($data['line_no']) || empty($data['text'])) {
            return $this->json(['error' => 'line_no and text required'], 400);
        }

        $annotation = new Annotation();
        $annotation->setTraceFile($traceFile)
            ->setLineNo((int)$data['line_no'])
            ->setText($data['text']);

        $this->em->persist($annotation);
        $this->em->flush();

        return $this->json(['id' => $annotation->getId()], 201);
    }

    #[Route('/annotations/item/{annotId}', methods: ['DELETE'])]
    public function deleteAnnotation(int $annotId): JsonResponse
    {
        $annotation = $this->annotRepo->find($annotId);
        if (!$annotation) return $this->json(['error' => 'Not found'], 404);

        $this->em->remove($annotation);
        $this->em->flush();

        return $this->json(['ok' => true]);
    }

    #[Route('/annotations/{id}/export', methods: ['GET'])]
    public function exportAnnotations(int $id): Response
    {
        $traceFile = $this->traceRepo->find($id);
        if (!$traceFile) return $this->json(['error' => 'Not found'], 404);

        $annotations = $this->annotRepo->findByTraceFile($id);
        $md = "# Trace Analysis: {$traceFile->getOriginalName()}\n\n";
        foreach ($annotations as $a) {
            $md .= "## Line {$a->getLineNo()}\n\n{$a->getText()}\n\n---\n\n";
        }

        return new Response($md, 200, [
            'Content-Type'        => 'text/markdown',
            'Content-Disposition' => 'attachment; filename="annotations.md"',
        ]);
    }

    // ── Settings ──────────────────────────────────────────────────────────────

    private function getComposeFile(): string
    {
        // compose.yaml lives next to the symfony/ dir, i.e. one level up from /app inside container
        // On the host it is at the project root. We store a pointer in an env var or use a well-known path.
        return $_ENV['COMPOSE_FILE_PATH'] ?? '/compose/compose.yaml';
    }

    private function getSettingsPath(): string
    {
        return '/app/var/settings.json';
    }

    #[Route('/settings', methods: ['GET'])]
    public function getSettings(): JsonResponse
    {
        $path = $this->getSettingsPath();
        $settings = file_exists($path) ? json_decode(file_get_contents($path), true) : [];

        // Read current traces path from compose.yaml if available
        $composePath = $this->getComposeFile();
        if (file_exists($composePath)) {
            $content = file_get_contents($composePath);
            // Extract host path from volume line like "  - /host/path:/traces:ro"
            if (preg_match('/^\s*-\s+([^:]+):(\/traces):ro/m', $content, $m)) {
                $settings['traces_host_path'] = $settings['traces_host_path'] ?? trim($m[1]);
            }
        }

        $settings['traces_host_path'] = $settings['traces_host_path'] ?? '';
        $settings['project_path']        = ($settings['project_path']        ?? '') ?: (getenv('SOURCE_HOST_DIR')      ?: '');
        $settings['docker_project_path'] = ($settings['docker_project_path'] ?? '') ?: (getenv('SOURCE_CONTAINER_DIR') ?: '');
        $settings['ide_project_name']    = ($settings['ide_project_name']    ?? '') ?: (getenv('IDE_PROJECT_NAME')     ?: '');
        $settings['project_name']     = $settings['project_name']     ?? '';
        $settings['listener_filters'] = $settings['listener_filters'] ?? [];
        $settings['event_filters']    = $settings['event_filters']    ?? [];
        $settings['app_namespaces']   = $settings['app_namespaces']   ?? [];

        return $this->json($settings);
    }

    #[Route('/open-in-ide', methods: ['POST'])]
    public function openInIde(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        $containerPath = trim($body['path'] ?? '');
        $line = (int)($body['line'] ?? 0);

        if (!$containerPath) {
            return $this->json(['error' => 'path required'], 400);
        }

        $localPath = $containerPath;
        $dockerDir = getenv('SOURCE_CONTAINER_DIR') ?: '';
        $hostDir   = getenv('SOURCE_HOST_DIR') ?: '';
        if ($dockerDir && $hostDir && str_starts_with($containerPath, $dockerDir)) {
            $localPath = $hostDir . substr($containerPath, strlen($dockerDir));
        }

        if (!file_exists($localPath)) {
            return $this->json(['error' => 'file not found: ' . $localPath], 404);
        }

        $ideCmd = trim(getenv('IDE_CMD') ?: 'phpstorm');
        $cmd = escapeshellcmd($ideCmd) . ' --line ' . (int)$line . ' ' . escapeshellarg($localPath);
        exec($cmd . ' > /dev/null 2>&1 &');

        return $this->json(['ok' => true, 'path' => $localPath, 'line' => $line]);
    }

    #[Route('/settings', methods: ['POST'])]
    public function saveSettings(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        $tracesPath = trim($body['traces_host_path'] ?? '');
        $projectPath = trim($body['project_path'] ?? '');
        $dockerProjectPath = trim($body['docker_project_path'] ?? '');
        $projectName = trim($body['project_name'] ?? '');
        $listenerFilters = array_values(array_filter(array_map('trim', (array)($body['listener_filters'] ?? [])), fn($s) => $s !== ''));
        $eventFilters = array_values(array_filter(array_map('trim', (array)($body['event_filters'] ?? [])), fn($s) => $s !== ''));
        // app_namespaces: [{namespace: "App\\", label: "app"}, ...]
        $appNamespaces = array_values(array_filter((array)($body['app_namespaces'] ?? []), fn($e) => !empty($e['namespace'])));

        $existing = file_exists($this->getSettingsPath()) ? (json_decode(file_get_contents($this->getSettingsPath()), true) ?? []) : [];
        $settings = [
            'traces_host_path' => $tracesPath,
            'project_path'        => $projectPath,
            'docker_project_path' => $dockerProjectPath,
            'project_name'     => $projectName,
            'listener_filters' => $listenerFilters,
            'event_filters'    => $eventFilters,
            'app_namespaces'   => $appNamespaces,
        ] + array_filter($existing, fn($k) => str_starts_with($k, 'xdebug_'), ARRAY_FILTER_USE_KEY);

        // Persist settings
        $settingsDir = dirname($this->getSettingsPath());
        if (!is_dir($settingsDir) && !mkdir($settingsDir, 0755, true) && !is_dir($settingsDir)) {
            return $this->json(['error' => 'Cannot create settings directory'], 500);
        }
        file_put_contents($this->getSettingsPath(), json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Patch compose.yaml if we have access to it and a traces path was given
        $composePath = $this->getComposeFile();
        $composePatched = false;
        if ($tracesPath && file_exists($composePath)) {
            $content = file_get_contents($composePath);
            // Replace existing traces volume line
            $newLine = "      - {$tracesPath}:/traces:ro";
            $content = preg_replace('/^\s*-\s+[^:]+:(\/traces):ro.*$/m', $newLine, $content, -1, $count);
            if ($count > 0) {
                file_put_contents($composePath, $content);
                $composePatched = true;
            }
        }

        return $this->json(['ok' => true, 'compose_patched' => $composePatched]);
    }

    #[Route('/source', methods: ['GET'])]
    public function source(Request $request): JsonResponse
    {
        $file = $request->query->get('file', '');
        $hint = (int)$request->query->get('hint', 0); // any line inside the function
        $method = trim((string)$request->query->get('method', '')); // optional method name to disambiguate
        $class = trim((string)$request->query->get('class', ''));   // optional FQCN for Reflection lookup

        // Preferred path: PHP Reflection knows exactly where each method is declared,
        // including parent classes, traits, and interface inheritances. xdebug's
        // file_abs sometimes points at the *call site* file (where the method is
        // invoked) rather than the file where it's defined — for those cases
        // (e.g. Repository::method called from a Service), Reflection is the
        // only way to find the real declaration reliably.
        if ($class !== '' && $method !== '' && class_exists($class)) {
            try {
                $ref = new \ReflectionClass($class);
                if ($ref->hasMethod($method)) {
                    $m = $ref->getMethod($method);
                    $declFile = $m->getFileName();
                    $declFrom = $m->getStartLine();
                    $declTo   = $m->getEndLine();
                    if ($declFile && file_exists($declFile) && $declFrom > 0 && $declTo >= $declFrom) {
                        $allLines = file($declFile, FILE_IGNORE_NEW_LINES);
                        if ($allLines !== false) {
                            $lines = [];
                            for ($i = $declFrom - 1; $i < $declTo; $i++) {
                                $lines[$i + 1] = $allLines[$i];
                            }
                            return $this->json([
                                'file'    => $declFile,
                                'lines'   => $lines,
                                'fn_from' => $declFrom,
                                'fn_to'   => $declTo,
                                'resolved' => 'reflection',
                            ]);
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Fall through to heuristic lookup
            }
        }

        // Second path: PSR-4 file lookup against the analysed project. This handles
        // the common case where the project's own autoloader isn't loaded into the
        // xtrace container (e.g. monorepo setups), so Reflection can't see the class.
        // We translate the FQCN to its expected file path under project_path and
        // grep for the method declaration directly.
        if ($class !== '' && $method !== '') {
            $resolved = $this->resolveMethodInProject($class, $method);
            if ($resolved !== null) {
                [$declFile, $declFrom, $declTo] = $resolved;
                $allLines = file($declFile, FILE_IGNORE_NEW_LINES);
                if ($allLines !== false) {
                    $lines = [];
                    for ($i = $declFrom - 1; $i < $declTo; $i++) {
                        $lines[$i + 1] = $allLines[$i];
                    }
                    return $this->json([
                        'file'    => $declFile,
                        'lines'   => $lines,
                        'fn_from' => $declFrom,
                        'fn_to'   => $declTo,
                        'resolved' => 'psr4',
                    ]);
                }
            }
        }

        if (!$file || !str_starts_with($file, '/') || !file_exists($file) || !is_file($file)) {
            return $this->json(['error' => 'File not found on server: ' . basename($file)], 404);
        }

        // Load the whole file into lines array (PHP source files are small)
        $allLines = file($file, FILE_IGNORE_NEW_LINES);
        if ($allLines === false) return $this->json(['error' => 'unreadable'], 500);

        $total = count($allLines); // 0-indexed internally, 1-indexed externally

        // Defensive: hint must be within file bounds. Out-of-range hints happen when
        // the frontend mistakenly passes a trace line_no (millions) instead of a
        // file line (small int). Clamp to a valid position and let the user see the
        // top of the file rather than a 500 error.
        $hint = max(1, min($total, $hint));

        // Find function boundaries around $hint line
        $from = max(1, $hint - 1);
        $to   = min($total, $hint + 1);

        // xdebug puts the call site's line into file_abs, not the function declaration.
        // When a hint sits inside another method's body (e.g. line 24 is `$this->getSubaccounts($x);`),
        // a backward walk lands on the enclosing method (line 20: getUserSubaccountData)
        // instead of the actually-called method (line 43: getSubaccounts). The frontend
        // passes the target method name as ?method=...; locate its declaration precisely.
        $declLine = null;
        if ($method !== '') {
            $escaped = preg_quote($method, '/');
            // Match: "function getSubaccounts(" — declaration only, not call sites.
            for ($i = 0; $i < $total; $i++) {
                if (preg_match('/^\s*(public|protected|private|static|abstract|final|\s)*function\s+' . $escaped . '\s*\(/', $allLines[$i])) {
                    $declLine = $i + 1;
                    break;
                }
            }
        }

        if ($declLine !== null) {
            $from = $declLine;
        } elseif ($hint > 0) {
            // Fallback: walk backward from hint to find "function" keyword line
            for ($i = $hint - 1; $i >= max(0, $hint - 60); $i--) {
                if (preg_match('/^\s*(public|protected|private|static)?\s*function\s+\w+/', $allLines[$i])) {
                    $from = $i + 1; // convert to 1-indexed
                    break;
                }
            }
        }

        // Walk forward to find matching closing brace
        $depth = 0;
        $started = false;
        for ($i = $from - 1; $i < min($total, $from + 200); $i++) {
            $depth += substr_count($allLines[$i], '{') - substr_count($allLines[$i], '}');
            if (!$started && $depth > 0) $started = true;
            if ($started && $depth <= 0) { $to = $i + 1; break; }
        }

        $lines = [];
        for ($i = $from - 1; $i < $to; $i++) {
            $lines[$i + 1] = $allLines[$i];
        }

        return $this->json(['file' => $file, 'lines' => $lines, 'fn_from' => $from, 'fn_to' => $to]);
    }

    #[Route('/settings/restart', methods: ['POST'])]
    public function restartContainer(): JsonResponse
    {
        // Signal the host to restart via a sentinel file that an external watcher can pick up,
        // or attempt docker restart via shell (works if docker socket is mounted).
        $sentinelPath = '/app/var/restart_requested';
        file_put_contents($sentinelPath, time());

        // Try docker restart if socket is available
        $output = [];
        $code = 0;
        if (file_exists('/var/run/docker.sock')) {
            exec('docker compose -f ' . escapeshellarg($this->getComposeFile()) . ' restart app 2>&1', $output, $code);
        }

        return $this->json(['ok' => true, 'docker_available' => file_exists('/var/run/docker.sock'), 'output' => implode("\n", $output)]);
    }

    // ── Xdebug mode control ───────────────────────────────────────────────────

    private function getXdebugSettings(): array
    {
        $s = file_exists($this->getSettingsPath()) ? json_decode(file_get_contents($this->getSettingsPath()), true) : [];
        return [
            'container'        => (getenv('XDEBUG_CONTAINER')     ?: null) ?? $s['xdebug_container']        ?? '',
            'compose_dir'      => (getenv('XDEBUG_COMPOSE_DIR')   ?: null) ?? $s['xdebug_compose_dir']      ?? '',
            'ini_path'         => '/usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini',
            'trace_dir_ctr'    => (getenv('XDEBUG_TRACE_DIR_CTR') ?: null) ?? $s['xdebug_trace_dir_ctr']    ?? '/traces',
            'traces_host_path' => $s['traces_host_path'] ?? '',
            'client_host'      => 'host.docker.internal',
            'client_port'      => '9003',
            'idekey'           => 'PHPSTORM',
        ];
    }

    #[Route('/xdebug/config', methods: ['POST'])]
    public function xdebugSaveConfig(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        $path = $this->getSettingsPath();
        $existing = file_exists($path) ? (json_decode(file_get_contents($path), true) ?? []) : [];
        $existing['xdebug_container']  = trim($body['container']   ?? '');
        $existing['xdebug_compose_dir'] = trim($body['compose_dir'] ?? '');
        $settingsDir = dirname($path);
        if (!is_dir($settingsDir)) mkdir($settingsDir, 0755, true);
        file_put_contents($path, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $this->json(['ok' => true]);
    }

    private function resolveContainerId(array $xd): ?string
    {
        // Try by name first (works when container_name matches)
        $out = [];
        exec('docker inspect --format={{.Id}} ' . escapeshellarg($xd['container']) . ' 2>/dev/null', $out, $code);
        $id = trim(implode('', $out));
        if ($code === 0 && $id) return $id;
        return null;
    }

    private function dockerExec(array $xd, string $command): array
    {
        if (!$xd['container'] || !file_exists('/var/run/docker.sock')) {
            return ['ok' => false, 'error' => 'docker not available or container not configured'];
        }
        $id = $this->resolveContainerId($xd);
        if (!$id) {
            return ['ok' => false, 'error' => "container '{$xd['container']}' not found or not running"];
        }
        $cmd = 'docker exec -u root ' . escapeshellarg($id) . ' sh -c ' . escapeshellarg($command) . ' 2>&1';
        $output = [];
        exec($cmd, $output, $code);
        return ['ok' => $code === 0, 'output' => implode("\n", $output), 'code' => $code];
    }

    private function dockerCpIni(array $xd, string $iniContent): array
    {
        if (!$xd['container'] || !file_exists('/var/run/docker.sock')) {
            return ['ok' => false, 'error' => 'docker not available or container not configured'];
        }
        $id = $this->resolveContainerId($xd);
        if (!$id) {
            return ['ok' => false, 'error' => "container '{$xd['container']}' not found or not running"];
        }
        $tmp = tempnam(sys_get_temp_dir(), 'xdebug_ini_');
        file_put_contents($tmp, $iniContent);
        $cmd = 'docker cp ' . escapeshellarg($tmp) . ' ' . escapeshellarg($id . ':' . $xd['ini_path']) . ' 2>&1';
        $output = [];
        exec($cmd, $output, $code);
        unlink($tmp);
        if ($code === 0) {
            // docker cp creates files with mode 600 owned by root — make readable by php-fpm worker
            exec('docker exec -u root ' . escapeshellarg($id) . ' chmod 644 ' . escapeshellarg($xd['ini_path']) . ' 2>&1', $chmodOut, $chmodCode);
        }
        return ['ok' => $code === 0, 'output' => implode("\n", $output), 'code' => $code];
    }

    private function buildIni(array $xd, string $mode): string
    {
        $base = "zend_extension=xdebug\n"
              . "xdebug.client_host={$xd['client_host']}\n"
              . "xdebug.client_port={$xd['client_port']}\n"
              . "xdebug.log=/var/log/xdebug.log\n"
              . "xdebug.idekey={$xd['idekey']}\n";

        if ($mode === 'debug+trace') {
            return $base
                 . "xdebug.mode=debug,trace\n"
                 . "xdebug.start_with_request=yes\n"
                 . "xdebug.output_dir={$xd['trace_dir_ctr']}\n"
                 . "xdebug.trace_output_name=trace_%R_%t\n";
        }
        if ($mode === 'debug') {
            return $base
                 . "xdebug.mode=debug\n"
                 . "xdebug.start_with_request=trigger\n";
        }
        // off
        return $base . "xdebug.mode=off\n";
    }

    #[Route('/xdebug/status', methods: ['GET'])]
    public function xdebugStatus(): JsonResponse
    {
        $xd = $this->getXdebugSettings();
        if (!$xd['container']) {
            return $this->json(['configured' => false, 'mode' => null, 'running' => false]);
        }
        $res = $this->dockerExec($xd, 'cat ' . escapeshellarg($xd['ini_path']));
        if (!$res['ok']) {
            return $this->json(['configured' => true, 'running' => false, 'mode' => null, 'error' => $res['output'] ?? $res['error']]);
        }
        $mode = 'off';
        foreach (explode("\n", $res['output']) as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'xdebug.mode=')) {
                $val = trim(substr($line, strlen('xdebug.mode=')));
                if (str_contains($val, 'trace')) $mode = 'debug+trace';
                elseif (str_contains($val, 'debug')) $mode = 'debug';
                else $mode = 'off';
            }
        }
        return $this->json(['configured' => true, 'running' => true, 'mode' => $mode]);
    }

    private function dockerComposeRestart(array $xd): array
    {
        if (!file_exists('/var/run/docker.sock')) {
            return ['ok' => false, 'error' => 'docker socket not available'];
        }
        $output = [];
        $composeDir = $xd['compose_dir'] ?: null;
        if ($composeDir && is_dir($composeDir)) {
            $cmd = 'cd ' . escapeshellarg($composeDir) . ' && docker compose restart ' . escapeshellarg($xd['container']) . ' 2>&1';
        } else {
            $cmd = 'docker restart ' . escapeshellarg($xd['container']) . ' 2>&1';
        }
        exec($cmd, $output, $code);
        return ['ok' => $code === 0, 'output' => implode("\n", $output), 'code' => $code];
    }

    private function getSessionFile(): string
    {
        return sys_get_temp_dir() . '/xdebug-session-start';
    }

    private function saveSessionStart(): void
    {
        file_put_contents($this->getSessionFile(), (string) microtime(true));
    }

    private function loadSessionStart(): ?float
    {
        $f = $this->getSessionFile();
        if (!file_exists($f)) return null;
        $v = trim(file_get_contents($f));
        return is_numeric($v) ? (float) $v : null;
    }

    private function clearSessionStart(): void
    {
        $f = $this->getSessionFile();
        if (file_exists($f)) unlink($f);
    }

    private function findXtFile(string $dir): ?string
    {
        // Support both 'trace.xt' (parsed copy) and 'trace__*.xt' (original source)
        $traceFile = rtrim($dir, '/') . '/trace.xt';
        if (file_exists($traceFile)) {
            return $traceFile;
        }
        $files = glob(rtrim($dir, '/') . '/trace__*.xt');
        return $files[0] ?? null;
    }

    #[Route('/xdebug/set', methods: ['POST'])]
    public function xdebugSet(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        $mode = $body['mode'] ?? 'off'; // 'off' | 'debug' | 'debug+trace'
        if (!in_array($mode, ['off', 'debug', 'debug+trace'], true)) {
            return $this->json(['ok' => false, 'error' => 'invalid mode'], 400);
        }

        $xd = $this->getXdebugSettings();
        if (!$xd['container']) {
            return $this->json(['ok' => false, 'error' => 'xdebug container not configured in Settings → Xdebug']);
        }
        // Create trace dir if switching to trace mode
        if ($mode === 'debug+trace') {
            $this->dockerExec($xd, 'mkdir -p ' . escapeshellarg($xd['trace_dir_ctr']));
        }

        $ini = $this->buildIni($xd, $mode);
        $cp  = $this->dockerCpIni($xd, $ini);
        if (!$cp['ok']) {
            return $this->json(['ok' => false, 'error' => $cp['output'] ?? $cp['error']]);
        }

        // Save/clear session start (same as Python _save_session_start / trace_organize)
        if ($mode === 'debug+trace') {
            $this->saveSessionStart();
        } else {
            // turning off — session ends, organize happens client-side or via /xdebug/organize
            $this->clearSessionStart();
        }

        // Full container restart — required for php-fpm to re-read the ini (same as Python _restart_container)
        $restart = $this->dockerComposeRestart($xd);
        if (!$restart['ok']) {
            return $this->json(['ok' => false, 'error' => 'ini written but restart failed: ' . ($restart['output'] ?? $restart['error'])]);
        }

        return $this->json(['ok' => true, 'mode' => $mode]);
    }

    #[Route('/xdebug/organize', methods: ['POST'])]
    public function xdebugOrganize(): JsonResponse
    {
        // Use the mounted traces dir inside the container (same volume as host TRACES_DIR)
        $tracesHostPath = rtrim(getenv('TRACES_SOURCE_DIR') ?: '/traces', '/');
        if (!is_dir($tracesHostPath)) {
            return $this->json(['ok' => false, 'error' => "traces dir not found: $tracesHostPath"]);
        }

        $sessionStart = $this->loadSessionStart();
        $files = glob(rtrim($tracesHostPath, '/') . '/trace_*.xt') ?: [];

        if ($sessionStart !== null) {
            $files = array_filter($files, fn($f) => filemtime($f) >= $sessionStart - 1);
        }

        if (!$files) {
            $this->clearSessionStart();
            return $this->json(['ok' => true, 'message' => 'no trace files', 'folder' => null]);
        }

        usort($files, fn($a, $b) => filemtime($a) <=> filemtime($b));
        $startTs  = $sessionStart ?? filemtime($files[0]);
        $startDt  = date('Y-m-d_H-i-s', (int) $startTs);

        // Extract URL slug from first filename: trace__api_security_login.xt → api_security_login
        $firstName = pathinfo(basename($files[0]), PATHINFO_FILENAME);
        $urlPart   = substr($firstName, strlen('trace'));
        $urlSlug   = substr(str_replace('/', '_', ltrim($urlPart, '_')), 0, 40) ?: 'unknown';

        $folderName = "{$startDt}_{$urlSlug}";
        $folderPath = rtrim($tracesHostPath, '/') . '/' . $folderName;
        if (!is_dir($folderPath)) mkdir($folderPath, 0755, true);

        $moved = 0;
        foreach ($files as $f) {
            rename($f, $folderPath . '/' . basename($f));
            $moved++;
        }

        $this->clearSessionStart();
        return $this->json(['ok' => true, 'message' => "organized {$moved} file" . ($moved !== 1 ? 's' : ''), 'folder' => $folderName]);
    }

    #[Route('/xdebug/clear', methods: ['POST'])]
    public function xdebugClear(): JsonResponse
    {
        $xd = $this->getXdebugSettings();
        if (!$xd['container']) {
            return $this->json(['ok' => false, 'error' => 'xdebug not configured']);
        }
        $res = $this->dockerExec($xd, 'rm -f ' . escapeshellarg($xd['trace_dir_ctr']) . '/trace_*.xt 2>/dev/null; echo done');
        // also clear session start
        $this->clearSessionStart();
        return $this->json(['ok' => $res['ok'], 'output' => $res['output'] ?? '']);
    }
}
