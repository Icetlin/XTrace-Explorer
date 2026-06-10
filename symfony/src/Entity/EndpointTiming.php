<?php

namespace App\Entity;

use App\Repository\EndpointTimingRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EndpointTimingRepository::class)]
#[ORM\Index(columns: ['trace_name'], name: 'idx_endpoint_timing_trace_name')]
class EndpointTiming
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\Column(length: 255)]
    private string $endpointUrl;

    #[ORM\Column(length: 10)]
    private string $endpointMethod;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $traceName = null;

    #[ORM\Column]
    private int $durationMs;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): int { return $this->id; }
    public function getEndpointUrl(): string { return $this->endpointUrl; }
    public function setEndpointUrl(string $v): static { $this->endpointUrl = $v; return $this; }
    public function getEndpointMethod(): string { return $this->endpointMethod; }
    public function setEndpointMethod(string $v): static { $this->endpointMethod = $v; return $this; }
    public function getTraceName(): ?string { return $this->traceName; }
    public function setTraceName(?string $v): static { $this->traceName = $v; return $this; }
    public function getDurationMs(): int { return $this->durationMs; }
    public function setDurationMs(int $v): static { $this->durationMs = $v; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
