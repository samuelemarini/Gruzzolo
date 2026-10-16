<?php

namespace App\Entity;

use App\Repository\AccountRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AccountRepository::class)]
class Account implements OwnedInterface
{
    public const KINDS = [
        'checking' => 'Conto corrente',
        'savings' => 'Accantonamento / risparmio',
        'cash' => 'Contanti',
        'card' => 'Carta prepagata',
        'other' => 'Altro',
    ];

    public const KIND_ICONS = [
        'checking' => 'landmark',
        'savings' => 'piggy-bank',
        'cash' => 'banknote',
        'card' => 'wallet',
        'other' => 'wallet',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 80)]
    private string $name = '';

    #[ORM\Column(length: 20)]
    private string $kind = 'checking';

    #[ORM\Column(length: 7)]
    private string $color = '#8AB4FF';

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, options: ['default' => '0.00'])]
    private string $initialBalance = '0.00';

    #[ORM\Column(options: ['default' => false])]
    private bool $isDefault = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $archived = false;

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = trim($name); return $this; }

    public function getKind(): string { return $this->kind; }
    public function setKind(string $kind): static { $this->kind = array_key_exists($kind, self::KINDS) ? $kind : 'other'; return $this; }
    public function getKindLabel(): string { return self::KINDS[$this->kind] ?? $this->kind; }
    public function getIcon(): string { return self::KIND_ICONS[$this->kind] ?? 'wallet'; }

    public function getColor(): string { return $this->color; }
    public function setColor(string $color): static { $this->color = $color; return $this; }

    public function getInitialBalance(): string { return $this->initialBalance; }
    public function setInitialBalance(string $initialBalance): static { $this->initialBalance = $initialBalance; return $this; }

    public function isDefault(): bool { return $this->isDefault; }
    public function setIsDefault(bool $isDefault): static { $this->isDefault = $isDefault; return $this; }

    public function isArchived(): bool { return $this->archived; }
    public function setArchived(bool $archived): static { $this->archived = $archived; return $this; }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): static { $this->position = $position; return $this; }
}
