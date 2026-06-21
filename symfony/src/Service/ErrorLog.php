<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Append-only JSON-lines error log. Written to by ErrorLogSubscriber on
 * kernel.exception, read by GET /api/errors for the live console in the
 * ctrl menu. We keep the most recent 500 entries.
 *
 * File format: one JSON object per line, newline-separated. The file is
 * rotated (renamed to errors.log.1) when it exceeds 1 MB; the oldest
 * generation is dropped.
 */
final class ErrorLog
{
    private const FILE = '/app/var/log/errors.log';
    private const FILE_OLD = '/app/var/log/errors.log.1';
    private const MAX_BYTES = 1_048_576;   // 1 MB
    private const MAX_ENTRIES = 500;

    public function append(array $entry): void
    {
        $dir = dirname(self::FILE);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        // Rotate if the file is getting large.
        if (is_file(self::FILE) && filesize(self::FILE) > self::MAX_BYTES) {
            @rename(self::FILE, self::FILE_OLD);
        }
        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        @file_put_contents(self::FILE, $line, FILE_APPEND | LOCK_EX);
    }

    /** @return list<array<string,mixed>> Newest last. */
    public function tail(int $limit = 50): array
    {
        if (!is_file(self::FILE)) return [];
        $lines = @file(self::FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        // Keep only the most recent MAX_ENTRIES so the file doesn't grow
        // unbounded in long-running dev sessions.
        if (count($lines) > self::MAX_ENTRIES) {
            $lines = array_slice($lines, -self::MAX_ENTRIES);
        }
        $lines = array_slice($lines, -$limit);
        $out = [];
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) $out[] = $decoded;
        }
        return $out;
    }

    public function clear(): void
    {
        @unlink(self::FILE);
    }
}
