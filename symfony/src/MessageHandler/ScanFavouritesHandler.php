<?php

namespace App\MessageHandler;

use App\Message\ScanFavouritesMessage;
use App\Repository\FavouritePatternRepository;
use App\Service\TraceIndex;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class ScanFavouritesHandler
{
    public function __construct(
        private readonly TraceIndex $traceIndex,
        private readonly FavouritePatternRepository $favRepo,
        private readonly string $tracesDir,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function __invoke(ScanFavouritesMessage $msg): void
    {
        $fileId = $msg->traceFileId;
        $cacheFile = $this->tracesDir . '/' . $fileId . '/fav_scan_cache/' . $msg->cacheKey . '.json';
        if (file_exists($cacheFile)) {
            return; // already done
        }

        $xtPath = $this->tracesDir . '/' . $fileId . '/trace.xt';
        if (!file_exists($xtPath)) {
            return;
        }

        $patterns = array_map(
            fn($f) => ['pattern' => $f->getPattern(), 'label' => $f->getLabel()],
            $this->favRepo->findAll()
        );

        $this->logger?->info('scanFavourites: start', ['fileId' => $fileId, 'patterns' => count($patterns)]);
        $start = microtime(true);
        try {
            $this->traceIndex->scanFavourites($fileId, $xtPath, $patterns);
        } catch (\Throwable $e) {
            $this->logger?->error('scanFavourites: failed', [
                'fileId' => $fileId, 'error' => $e->getMessage(),
            ]);
            return;
        }
        $elapsed = microtime(true) - $start;
        $this->logger?->info('scanFavourites: done', [
            'fileId' => $fileId, 'elapsed' => round($elapsed, 2),
        ]);
    }
}
