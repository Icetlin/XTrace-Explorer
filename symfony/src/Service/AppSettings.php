<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\AppSettingRepository;

/**
 * Thin facade over AppSettingRepository. The "off by default" rule for
 * Profiler+ is enforced here so callers never see `true` until the user
 * explicitly opts in via the UI.
 */
final class AppSettings
{
    public function __construct(
        private readonly AppSettingRepository $repo,
    ) {}

    public function profilerEnabled(): bool
    {
        // Strict default-off: if the row is missing for any reason, treat
        // the feature as disabled. The migration inserts the row, but
        // production rollouts without the migration still degrade safely.
        return $this->repo->getBool('profiler_enabled', false);
    }

    public function setProfilerEnabled(bool $on): void
    {
        $this->repo->setBool('profiler_enabled', $on);
    }
}
