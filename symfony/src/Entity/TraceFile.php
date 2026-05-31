<?php

namespace App\Entity;

use App\Repository\TraceFileRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TraceFileRepository::class)]
class TraceFile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\Column(length: 255)]
    private string $originalName;

    #[ORM\Column(length: 64)]
    private string $fileHash;

    #[ORM\Column(length: 20)]
    private string $status = 'pending'; // pending | parsing | ready | error

    #[ORM\Column]
    private int $progress = 0;

    #[ORM\Column(nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): int { return $this->id; }
    public function getOriginalName(): string { return $this->originalName; }
    public function setOriginalName(string $v): static { $this->originalName = $v; return $this; }
    public function getFileHash(): string { return $this->fileHash; }
    public function setFileHash(string $v): static { $this->fileHash = $v; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): static { $this->status = $v; return $this; }
    public function getProgress(): int { return $this->progress; }
    public function setProgress(int $v): static { $this->progress = $v; return $this; }
    public function getErrorMessage(): ?string { return $this->errorMessage; }
    public function setErrorMessage(?string $v): static { $this->errorMessage = $v; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
