<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProfilerQueryRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One SQL query captured by the Symfony Profiler DB panel and persisted as
 * part of a ProfilerSnapshot.
 *
 * Why a separate row (not a JSON blob inside ProfilerSnapshot)?
 *  - Indexed lookup by (snapshot, n) is cheap
 *  - Future "show only SELECTs" / "show only the ones taking >50ms" filters
 *    don't need a full table scan of a 10MB JSON blob
 *  - We can attach more columns later (e.g. table_name) without migrations
 *    blowing up
 */
#[ORM\Entity(repositoryClass: ProfilerQueryRepository::class)]
#[ORM\Index(columns: ['snapshot_id', 'n'], name: 'idx_profiler_query_snapshot_n')]
class ProfilerQuery
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\ManyToOne(targetEntity: ProfilerSnapshot::class, inversedBy: 'queries')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ProfilerSnapshot $snapshot;

    #[ORM\Column]
    private int $n;

    #[ORM\Column(type: 'text')]
    private string $sql;

    /** Raw time string, e.g. "1.2 ms", "345 micros" */
    #[ORM\Column(length: 32)]
    private string $time;

    #[ORM\Column]
    private float $timeMs = 0.0;

    /** SQL parameters as a JSON-encoded string (assoc or list). */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $paramsJson = null;

    /** Caller = first non-framework frame of the backtrace. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $callerClass = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $callerMethod = null;

    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $callerFile = null;

    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $callerHostPath = null;

    #[ORM\Column(nullable: true)]
    private ?int $callerLine = null;

    /** Full backtrace, JSON-encoded. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $backtraceJson = null;

    public function getId(): int { return $this->id; }
    public function getSnapshot(): ProfilerSnapshot { return $this->snapshot; }
    public function setSnapshot(ProfilerSnapshot $v): static { $this->snapshot = $v; return $this; }
    public function getN(): int { return $this->n; }
    public function setN(int $v): static { $this->n = $v; return $this; }
    public function getSql(): string { return $this->sql; }
    public function setSql(string $v): static { $this->sql = $v; return $this; }
    public function getTime(): string { return $this->time; }
    public function setTime(string $v): static { $this->time = $v; return $this; }
    public function getTimeMs(): float { return $this->timeMs; }
    public function setTimeMs(float $v): static { $this->timeMs = $v; return $this; }
    public function getParamsJson(): ?string { return $this->paramsJson; }
    public function setParamsJson(?string $v): static { $this->paramsJson = $v; return $this; }
    public function getCallerClass(): ?string { return $this->callerClass; }
    public function setCallerClass(?string $v): static { $this->callerClass = $v; return $this; }
    public function getCallerMethod(): ?string { return $this->callerMethod; }
    public function setCallerMethod(?string $v): static { $this->callerMethod = $v; return $this; }
    public function getCallerFile(): ?string { return $this->callerFile; }
    public function setCallerFile(?string $v): static { $this->callerFile = $v; return $this; }
    public function getCallerHostPath(): ?string { return $this->callerHostPath; }
    public function setCallerHostPath(?string $v): static { $this->callerHostPath = $v; return $this; }
    public function getCallerLine(): ?int { return $this->callerLine; }
    public function setCallerLine(?int $v): static { $this->callerLine = $v; return $this; }
    public function getBacktraceJson(): ?string { return $this->backtraceJson; }
    public function setBacktraceJson(?string $v): static { $this->backtraceJson = $v; return $this; }
}