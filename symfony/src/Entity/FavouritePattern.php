<?php

namespace App\Entity;

use App\Repository\FavouritePatternRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FavouritePatternRepository::class)]
class FavouritePattern
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\Column(type: 'string', length: 500)]
    private string $pattern;

    #[ORM\Column(type: 'string', length: 200, nullable: true)]
    private ?string $label = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): int { return $this->id; }
    public function getPattern(): string { return $this->pattern; }
    public function setPattern(string $v): static { $this->pattern = $v; return $this; }
    public function getLabel(): ?string { return $this->label; }
    public function setLabel(?string $v): static { $this->label = $v; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
