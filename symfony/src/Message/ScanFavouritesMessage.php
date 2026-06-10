<?php

namespace App\Message;

class ScanFavouritesMessage
{
    public function __construct(
        public readonly int $traceFileId,
        public readonly string $cacheKey,
    ) {}
}
