<?php

namespace App\Entity;

use App\Repository\AccountRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
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

    /**
     * Utenti con cui il proprietario condivide il conto: vedono saldo e movimenti e possono aggiungerne.
     *
     * @var Collection<int, User>
     */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'account_member')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(onDelete: 'CASCADE')]
    private Collection $members;

    /** Utenti che non vogliono il conto nella home (saldo, totali e ultimi movimenti): scelta di ognuno, anche sui conti condivisi. */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'account_home_hidden')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(onDelete: 'CASCADE')]
    private Collection $hiddenFromHome;

    public function __construct(User $user)
    {
        $this->user = $user;
        $this->members = new ArrayCollection();
        $this->hiddenFromHome = new ArrayCollection();
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

    /** @return Collection<int, User> */
    public function getMembers(): Collection { return $this->members; }
    public function addMember(User $user): static { if ($user !== $this->user && !$this->members->contains($user)) { $this->members->add($user); } return $this; }
    public function removeMember(User $user): static { $this->members->removeElement($user); return $this; }
    public function isShared(): bool { return !$this->members->isEmpty(); }

    /** Proprietario o membro: puo' vedere il conto e registrarci movimenti. */
    public function isAccessibleBy(User $user): bool
    {
        return $this->user === $user || $this->members->contains($user);
    }

    public function isOwnedBy(User $user): bool { return $this->user === $user; }

    public function isOnHomeFor(User $user): bool { return !$this->hiddenFromHome->contains($user); }

    public function setOnHomeFor(User $user, bool $show): static
    {
        if ($show) {
            $this->hiddenFromHome->removeElement($user);
        } elseif (!$this->hiddenFromHome->contains($user)) {
            $this->hiddenFromHome->add($user);
        }

        return $this;
    }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): static { $this->position = $position; return $this; }
}
