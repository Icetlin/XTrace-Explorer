<?php

namespace App\Controller;

use App\Entity\Annotation;
use App\Entity\FavouritePattern;
use App\Entity\TraceFile;
use App\Message\ParseTraceMessage;
use App\Repository\AnnotationRepository;
use App\Repository\FavouritePatternRepository;
use App\Repository\TraceFileRepository;
use App\Service\TraceIndex;
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
        private readonly TraceIndex $traceIndex,
        private readonly MessageBusInterface $bus,
    ) {}

    #[Route('/browse', methods: ['GET'])]
    public function browse(): JsonResponse
    {
        $files = [];
        if (is_dir($this->tracesSourceDir)) {
            foreach (glob($this->tracesSourceDir . '/*.xt') as $path) {
                $files[] = [
                    'name' => basename($path),
                    'path' => $path,
                    'size' => filesize($path),
                ];
            }
        }
        usort($files, fn($a, $b) => $b['size'] <=> $a['size']);
        return $this->json($files);
    }

    #[Route('/open', methods: ['POST'])]
    public function open(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $filename = $data['filename'] ?? '';

        // Security: only allow files from tracesSourceDir, no path traversal
        $realSource = realpath($this->tracesSourceDir . '/' . basename($filename));
        if (!$realSource || !str_starts_with($realSource, realpath($this->tracesSourceDir))) {
            return $this->json(['error' => 'Invalid file'], 400);
        }

        // Check if already imported (same path)
        $existing = $this->traceRepo->findOneBy(['originalName' => basename($filename)]);
        if ($existing && $existing->getStatus() === 'ready') {
            return $this->json(['file_id' => $existing->getId(), 'status' => $existing->getStatus()]);
        }

        $traceFile = new TraceFile();
        $traceFile->setOriginalName(basename($filename));
        $traceFile->setFileHash(md5($realSource));

        $this->em->persist($traceFile);
        $this->em->flush();

        $dir = $this->tracesDir . '/' . $traceFile->getId();
        mkdir($dir, 0755, true);
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
        mkdir($dir, 0755, true);
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

        return $this->json([
            'file_id'  => $traceFile->getId(),
            'name'     => $traceFile->getOriginalName(),
            'status'   => $traceFile->getStatus(),
            'progress' => $traceFile->getProgress(),
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

        $traceFile->setStatus('pending')->setProgress(0);
        $this->em->flush();
        $this->bus->dispatch(new ParseTraceMessage($traceFile->getId()));

        return $this->json(['ok' => true, 'file_id' => $id]);
    }

    #[Route('/toc/{id}', methods: ['GET'])]
    public function toc(int $id): JsonResponse
    {
        $traceFile = $this->traceRepo->find($id);
        if (!$traceFile || $traceFile->getStatus() !== 'ready') {
            return $this->json(['error' => 'Not ready'], 404);
        }

        $path = $this->tracesDir . '/' . $id . '/toc.json';
        if (!file_exists($path)) {
            return $this->json([]);
        }
        return new JsonResponse(file_get_contents($path), 200, [], true);
    }

    #[Route('/meta/{id}', methods: ['GET'])]
    public function meta(int $id): JsonResponse
    {
        $traceFile = $this->traceRepo->find($id);
        if (!$traceFile || $traceFile->getStatus() !== 'ready') {
            return $this->json(['error' => 'Not ready'], 404);
        }

        $metaPath = $this->tracesDir . '/' . $id . '/meta.json';
        if (file_exists($metaPath)) {
            return new JsonResponse(file_get_contents($metaPath), 200, [], true);
        }

        // Fallback: derive total_lines from last key in line_index
        $indexPath = $this->tracesDir . '/' . $id . '/line_index.json';
        $index = json_decode(file_get_contents($indexPath), true);
        $lastIndexedLine = (int)array_key_last($index);
        return $this->json(['total_lines' => $lastIndexedLine]);
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
        $filter    = !$request->query->getBoolean('raw', false);

        if ($lineNo <= 0) return $this->json(['error' => 'line_no required'], 400);

        $xtPath = $this->tracesDir . '/' . $id . '/trace.xt';
        $children = $this->traceIndex->getChildren($id, $xtPath, $lineNo, $callDepth, $filter);

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

        return $this->json($children);
    }

    #[Route('/favourites-scan/{id}', methods: ['GET'])]
    public function favouritesScan(int $id): JsonResponse
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

        $xtPath = $this->tracesDir . '/' . $id . '/trace.xt';
        $result = $this->traceIndex->scanFavourites($id, $xtPath, $patterns);

        // Ensure ei→li maps are always JSON objects even when keys start at 0
        // (PHP json_encode treats ['0'=>...] as array; cast inner maps to stdClass)
        $normalized = [];
        foreach ($result as $eiKey => $listeners) {
            $normalized[$eiKey] = (object)array_map(fn($hits) => array_values($hits), $listeners);
        }
        return $this->json((object)$normalized);
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

        return $this->json(['id' => $fav->getId()], 201);
    }

    #[Route('/favourites/{favId}', methods: ['DELETE'])]
    public function deleteFavourite(int $favId): JsonResponse
    {
        $fav = $this->favRepo->find($favId);
        if (!$fav) return $this->json(['error' => 'Not found'], 404);
        $this->em->remove($fav);
        $this->em->flush();
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
        $path = $_ENV['COMPOSE_FILE_PATH'] ?? '/compose/compose.yaml';
        return $path;
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
        $settings['project_path'] = $settings['project_path'] ?? '';
        $settings['project_name'] = $settings['project_name'] ?? '';
        $settings['listener_filters'] = $settings['listener_filters'] ?? [];
        $settings['app_namespaces'] = $settings['app_namespaces'] ?? [];

        return $this->json($settings);
    }

    #[Route('/settings', methods: ['POST'])]
    public function saveSettings(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        $tracesPath = trim($body['traces_host_path'] ?? '');
        $projectPath = trim($body['project_path'] ?? '');
        $projectName = trim($body['project_name'] ?? '');
        $listenerFilters = array_values(array_filter(array_map('trim', (array)($body['listener_filters'] ?? [])), fn($s) => $s !== ''));
        // app_namespaces: [{namespace: "App\\", label: "app"}, ...]
        $appNamespaces = array_values(array_filter((array)($body['app_namespaces'] ?? []), fn($e) => !empty($e['namespace'])));

        $settings = [
            'traces_host_path' => $tracesPath,
            'project_path'     => $projectPath,
            'project_name'     => $projectName,
            'listener_filters' => $listenerFilters,
            'app_namespaces'   => $appNamespaces,
        ];

        // Persist settings
        @mkdir(dirname($this->getSettingsPath()), 0755, true);
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
}
