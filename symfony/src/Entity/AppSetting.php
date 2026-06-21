<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AppSettingRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Generic key-value store for global application settings.
 *
 * Used for user-toggleable preferences that are not infrastructure
 * (i.e. don't belong in env vars) and aren't per-trace. Currently:
 *   - profiler_enabled : "1" | "0" — turns Profiler+ data source on/off
 *
 * Persisted in the database so the value survives container restarts,
 * image rebuilds, and is visible from the MCP / logs.
 */
#[ORM\Entity(repositoryClass: AppSettingRepository::class)]
class AppSetting
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $key;

    #[ORM\Column(type: 'text')]
    private string $value;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $key, string $value = '')
    {
        $this->key = $key;
        $this->value = $value;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getKey(): string { return $this->key; }
    public function getValue(): string { return $this->value; }
    public function setValue(string $v): static { $this->value = $v; $this->updatedAt = new \DateTimeImmutable(); return $this; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
