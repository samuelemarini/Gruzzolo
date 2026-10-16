<?php

namespace App\Entity;

use App\Repository\TagRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TagRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_tag_user_name', columns: ['user_id', 'name'])]
class Tag implements OwnedInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 60)]
    private string $name = '';

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(length: 7)]
    private string $color = '#C3A6FF';

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, string $name)
    {
        $this->user = $user;
        $this->setName($name);
        $this->createdAt = new \DateTimeImmutable();
    }

    /** "Festa Giulia!" → "festa-giulia": le etichette si scrivono come hashtag. */
    public static function normalize(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = ltrim($name, '#');
        $name = preg_replace('/[^\p{L}\p{N}]+/u', '-', $name) ?? '';

        return mb_substr(trim($name, '-'), 0, 60);
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = self::normalize($name); return $this; }

    public function getTitle(): ?string { return $this->title; }
    public function setTitle(?string $title): static { $this->title = $title !== null && trim($title) !== '' ? trim($title) : null; return $this; }

    public function getColor(): string { return $this->color; }
    public function setColor(string $color): static { $this->color = $color; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
