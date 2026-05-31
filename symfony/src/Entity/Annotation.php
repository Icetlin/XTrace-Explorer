<?php

namespace App\Entity;

use App\Repository\AnnotationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AnnotationRepository::class)]
class Annotation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TraceFile $traceFile;

    #[ORM\Column]
    private int $lineNo;

    #[ORM\Column(type: 'text')]
    private string $text;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): int { return $this->id; }
    public function getTraceFile(): TraceFile { return $this->traceFile; }
    public function setTraceFile(TraceFile $v): static { $this->traceFile = $v; return $this; }
    public function getLineNo(): int { return $this->lineNo; }
    public function setLineNo(int $v): static { $this->lineNo = $v; return $this; }
    public function getText(): string { return $this->text; }
    public function setText(string $v): static { $this->text = $v; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
