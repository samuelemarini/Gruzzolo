<?php

namespace App\Entity;

use App\Repository\CategoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CategoryRepository::class)]
/**
 * Categoria di un conto: ogni conto ha il suo insieme (il conto condiviso ha le sue, usate da entrambi).
 */
class Category
{
    public const EXPENSE = 'expense';
    public const INCOME = 'income';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Account $account;

    #[ORM\ManyToOne(inversedBy: 'children')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Category $parent = null;

    /** @var Collection<int, Category> */
    #[ORM\OneToMany(mappedBy: 'parent', targetEntity: Category::class)]
    #[ORM\OrderBy(['position' => \SortDirection::Ascending, 'name' => \SortDirection::Ascending])]
    private Collection $children;

    #[ORM\Column(length: 60)]
    private string $name = '';

    #[ORM\Column(length: 10)]
    private string $kind = self::EXPENSE;

    #[ORM\Column(length: 40)]
    private string $icon = 'circle-ellipsis';

    #[ORM\Column(length: 7)]
    private string $color = '#8AB4FF';

    /** Categoria carburante: il movimento chiede veicolo, km, litri e prezzo. */
    #[ORM\Column(options: ['default' => false])]
    private bool $fuel = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $archived = false;

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    public function __construct(Account $account)
    {
        $this->account = $account;
        $this->children = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getAccount(): Account { return $this->account; }

    /** Proprietario del conto a cui appartiene la categoria. */
    public function getUser(): User { return $this->account->getUser(); }

    public function isAccessibleBy(User $user): bool { return $this->account->isAccessibleBy($user); }

    public function getParent(): ?Category { return $this->parent; }
    public function setParent(?Category $parent): static { $this->parent = $parent; return $this; }

    /** @return Collection<int, Category> */
    public function getChildren(): Collection { return $this->children; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = trim($name); return $this; }

    /** "Trasporti › Carburante" per le sottocategorie. */
    public function getFullName(): string
    {
        return $this->parent ? $this->parent->getName().' › '.$this->name : $this->name;
    }

    public function getKind(): string { return $this->kind; }
    public function setKind(string $kind): static { $this->kind = $kind === self::INCOME ? self::INCOME : self::EXPENSE; return $this; }
    public function isIncome(): bool { return $this->kind === self::INCOME; }

    public function getIcon(): string { return $this->icon; }
    public function setIcon(string $icon): static { $this->icon = $icon; return $this; }

    public function getColor(): string { return $this->color; }
    public function setColor(string $color): static { $this->color = $color; return $this; }

    public function isFuel(): bool { return $this->fuel; }
    public function setFuel(bool $fuel): static { $this->fuel = $fuel; return $this; }

    public function isArchived(): bool { return $this->archived; }
    public function setArchived(bool $archived): static { $this->archived = $archived; return $this; }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): static { $this->position = $position; return $this; }
}
