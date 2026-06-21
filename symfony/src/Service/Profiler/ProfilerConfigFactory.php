<?php

declare(strict_types=1);

namespace App\Service\Profiler;

/**
 * Builds a ProfilerConfig from process environment.
 *
 * Kept as a thin factory so the Symfony container can wire it as a service
 * with a single binding. There is no settings.json to read — env is the
 * only source of truth.
 */
final class ProfilerConfigFactory
{
    public function create(): ProfilerConfig
    {
        return ProfilerConfig::fromEnv();
    }
}
