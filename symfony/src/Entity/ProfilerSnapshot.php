<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProfilerSnapshotRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * One captured profiler panel for a given TraceFile.
 *
 * A trace file may have 0..1 linked profiler snapshots. Re-running find/refresh
 * REPLACES the snapshot (and cascades to ProfilerQuery rows).
 *
 * Status:
 *   - "auto"    : token was matched automatically via listRecent() heuristics
 *   - "manual"  : token was provided by the user via link endpoint
 *   - "error"   : last fetch attempt failed; see errorMessage
 */
#[ORM\Entity(repositoryClass: ProfilerSnapshotRepository::class)]
#[ORM\Index(columns: ['trace_file_id'], name: 'idx_profiler_snapshot_trace_file')]
#[ORM\Index(columns: ['token'], name: 'idx_profiler_snapshot_token')]
class ProfilerSnapshot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\ManyToOne(targetEntity: TraceFile::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TraceFile $traceFile;

    /** Hex token from /_profiler/<token> */
    #[ORM\Column(length: 64)]
    private string $token;

    /** Base URL of the profiler app at the time of capture (e.g. https://systeme.local) */
    #[ORM\Column(length: 255)]
    private string $baseUrl;

    /** "auto" | "manual" | "error" */
    #[ORM\Column(length: 16)]
    private string $status = 'manual';

    #[ORM\Column(nullable: true)]
    private ?string $errorMessage = null;

    /** HTTP method + path that was matched (for auto-found snapshots) */
    #[ORM\Column(length: 16, nullable: true)]
    private ?string $requestMethod = null;

    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $requestPath = null;

    #[ORM\Column]
    private int $totalQueries = 0;

    #[ORM\Column]
    private float $totalMs = 0.0;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $analysisJson = null;     // N+1, callers, slowest, etc.

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $rawJson = null;          // full panel for re-analysis later

    #[ORM\Column]
    private \DateTimeImmutable $capturedAt;

    #[ORM\OneToMany(
        targetEntity: ProfilerQuery::class,
        mappedBy: 'snapshot',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    private Collection $queries;

    public function __construct()
    {
        $this->capturedAt = new \DateTimeImmutable();
        $this->queries = new ArrayCollection();
    }

    public function getId(): int { return $this->id; }
    public function getTraceFile(): TraceFile { return $this->traceFile; }
    public function setTraceFile(TraceFile $v): static { $this->traceFile = $v; return $this; }
    public function getToken(): string { return $this->token; }
    public function setToken(string $v): static { $this->token = $v; return $this; }
    public function getBaseUrl(): string { return $this->baseUrl; }
    public function setBaseUrl(string $v): static { $this->baseUrl = $v; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): static { $this->status = $v; return $this; }
    public function getErrorMessage(): ?string { return $this->errorMessage; }
    public function setErrorMessage(?string $v): static { $this->errorMessage = $v; return $this; }
    public function getRequestMethod(): ?string { return $this->requestMethod; }
    public function setRequestMethod(?string $v): static { $this->requestMethod = $v; return $this; }
    public function getRequestPath(): ?string { return $this->requestPath; }
    public function setRequestPath(?string $v): static { $this->requestPath = $v; return $this; }
    public function getTotalQueries(): int { return $this->totalQueries; }
    public function setTotalQueries(int $v): static { $this->totalQueries = $v; return $this; }
    public function getTotalMs(): float { return $this->totalMs; }
    public function setTotalMs(float $v): static { $this->totalMs = $v; return $this; }
    public function getAnalysisJson(): ?string { return $this->analysisJson; }
    public function setAnalysisJson(?string $v): static { $this->analysisJson = $v; return $this; }
    public function getRawJson(): ?string { return $this->rawJson; }
    public function setRawJson(?string $v): static { $this->rawJson = $v; return $this; }
    public function getCapturedAt(): \DateTimeImmutable { return $this->capturedAt; }
    public function setCapturedAt(\DateTimeImmutable $v): static { $this->capturedAt = $v; return $this; }

    /** @return Collection<int, ProfilerQuery> */
    public function getQueries(): Collection { return $this->queries; }

    public function addQuery(ProfilerQuery $q): static
    {
        if (!$this->queries->contains($q)) {
            $this->queries->add($q);
            $q->setSnapshot($this);
        }
        return $this;
    }

    public function clearQueries(): static
    {
        foreach ($this->queries as $q) {
            $this->queries->removeElement($q);
        }
        return $this;
    }
}