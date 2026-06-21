<?php

declare(strict_types=1);

namespace App\Service\Profiler;

/**
 * Resolved configuration for talking to a Symfony app's WebProfilerBundle.
 *
 * Source of truth, by field:
 *
 *   baseUrl        — env PROFILER_BASE_URL (infrastructure)
 *   srcPrefix      — env SOURCE_CONTAINER_DIR (shared with inline source view)
 *   hostPrefix     — env SOURCE_HOST_DIR (shared with inline source view)
 *   insecure       — hardcoded true (dev only; the target app's cert is
 *                    self-signed)
 *   timeoutSec     — hardcoded 1800 (30 minutes; the DB panel HTML is big
 *                    and one-shot)
 *   skipPrefixes   — built-in defaults
 *
 * The on/off toggle (which the user toggles in Settings → Profiler+) is NOT
 * here — it's a user preference stored in settings.json, owned by the
 * controller layer.
 */
final class ProfilerConfig
{
    public const DEFAULT_TIMEOUT_SEC = 1800;

    public function __construct(
        public readonly ?string $baseUrl = null,
        public readonly ?string $srcPrefix = null,
        public readonly ?string $hostPrefix = null,
        public readonly bool $insecure = true,
        public readonly int $timeoutSec = self::DEFAULT_TIMEOUT_SEC,
        public readonly array $skipPrefixes = ['Doctrine\\', 'Symfony\\', 'PDO', 'Propel'],
    ) {}

    /**
     * Build from env. Pass $_SERVER explicitly in tests.
     *
     * @param array<string,string>|null $env
     */
    public static function fromEnv(?array $env = null): self
    {
        $baseUrl   = self::readEnv($env, 'PROFILER_BASE_URL');
        $srcPrefix = self::readEnv($env, 'SOURCE_CONTAINER_DIR') ?: self::readEnv($env, 'PROFILER_SRC_PREFIX');
        $hostPrefix = self::readEnv($env, 'SOURCE_HOST_DIR') ?: self::readEnv($env, 'PROFILER_HOST_PREFIX');

        return new self(
            baseUrl: $baseUrl !== '' ? $baseUrl : null,
            srcPrefix: $srcPrefix !== '' ? $srcPrefix : null,
            hostPrefix: $hostPrefix !== '' ? $hostPrefix : null,
            insecure: true,
            timeoutSec: self::DEFAULT_TIMEOUT_SEC,
        );
    }

    public function isUsable(bool $userEnabled): bool
    {
        return $userEnabled && $this->baseUrl !== null;
    }

    private static function readEnv(?array $env, string $key): string
    {
        if ($env !== null) {
            $v = $env[$key] ?? null;
            return ($v === null || $v === false || $v === '') ? '' : (string) $v;
        }
        $v = getenv($key);
        return ($v === false || $v === '') ? '' : $v;
    }
}