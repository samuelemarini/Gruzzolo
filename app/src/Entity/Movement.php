<?php

namespace App\Entity;

use App\Repository\MovementRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un movimento: uscita, entrata o giroconto.
 *
 * L'importo e' sempre positivo, il segno lo da' il tipo. I giroconti spostano soldi fra conti
 * (o verso un investimento, toAccount null) e restano fuori dai report di entrate e uscite.
 */
#[ORM\Entity(repositoryClass: MovementRepository::class)]
#[ORM\Index(name: 'idx_movement_user_date', columns: ['user_id', 'date'])]
#[ORM\Index(name: 'idx_movement_user_import', columns: ['user_id', 'import_hash'])]
#[ORM\HasLifecycleCallbacks]
class Movement implements OwnedInterface
{
    public const EXPENSE = 'expense';
    public const INCOME = 'income';
    public const TRANSFER = 'transfer';

    public const KINDS = [self::EXPENSE => 'Uscita', self::INCOME => 'Entrata', self::TRANSFER => 'Giroconto'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 10)]
    private string $kind = self::EXPENSE;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $amount = '0.00';

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $date;

    #[ORM\Column(length: 160)]
    private string $description = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Account $account;

    /** Conto di destinazione dei giroconti (null = verso un investimento). */
    #[ORM\ManyToOne]
    private ?Account $toAccount = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Category $category = null;

    /** @var Collection<int, Tag> */
    #[ORM\ManyToMany(targetEntity: Tag::class)]
    #[ORM\JoinTable(name: 'movement_tag')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(onDelete: 'CASCADE')]
    #[ORM\OrderBy(['name' => \SortDirection::Ascending])]
    private Collection $tags;

    /** Qualsiasi spesa puo' riguardare un veicolo (carburante, assicurazione, tagliando...). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Vehicle $vehicle = null;

    #[ORM\OneToOne(mappedBy: 'movement', targetEntity: Refuel::class, cascade: ['persist', 'remove'])]
    private ?Refuel $refuel = null;

    #[ORM\OneToOne(mappedBy: 'movement', targetEntity: InvestmentEntry::class)]
    private ?InvestmentEntry $investmentEntry = null;

    /** Movimento arrivato da un file: l'impronta evita di importare due volte la stessa riga. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?ImportBatch $importBatch = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $importHash = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user, Account $account)
    {
        $this->user = $user;
        $this->account = $account;
        $this->tags = new ArrayCollection();
        $this->date = new \DateTimeImmutable('today');
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }

    public function getKind(): string { return $this->kind; }
    public function setKind(string $kind): static { $this->kind = array_key_exists($kind, self::KINDS) ? $kind : self::EXPENSE; return $this; }
    public function isExpense(): bool { return $this->kind === self::EXPENSE; }
    public function isIncome(): bool { return $this->kind === self::INCOME; }
    public function isTransfer(): bool { return $this->kind === self::TRANSFER; }

    public function getAmount(): string { return $this->amount; }
    public function setAmount(string $amount): static { $this->amount = $amount; return $this; }

    /** Importo con segno dal punto di vista del conto di partenza. */
    public function getSignedAmount(): float
    {
        return $this->kind === self::INCOME ? (float) $this->amount : -(float) $this->amount;
    }

    public function getDate(): \DateTimeImmutable { return $this->date; }
    public function setDate(\DateTimeImmutable $date): static { $this->date = $date; return $this; }

    public function getDescription(): string { return $this->description; }
    public function setDescription(string $description): static { $this->description = mb_substr(trim($description), 0, 160); return $this; }

    /** Descrizione se c'e', altrimenti il nome della categoria. */
    public function getTitle(): string
    {
        if ($this->description !== '') {
            return $this->description;
        }
        if ($this->kind === self::TRANSFER) {
            return $this->toAccount ? 'Giroconto verso '.$this->toAccount->getName() : 'Versamento investimento';
        }

        return $this->category?->getName() ?? self::KINDS[$this->kind];
    }

    public function getNote(): ?string { return $this->note; }
    public function setNote(?string $note): static { $this->note = $note !== null && trim($note) !== '' ? trim($note) : null; return $this; }

    public function getAccount(): Account { return $this->account; }
    public function setAccount(Account $account): static { $this->account = $account; return $this; }

    public function getToAccount(): ?Account { return $this->toAccount; }
    public function setToAccount(?Account $toAccount): static { $this->toAccount = $toAccount; return $this; }

    public function getCategory(): ?Category { return $this->category; }
    public function setCategory(?Category $category): static { $this->category = $category; return $this; }

    /** @return Collection<int, Tag> */
    public function getTags(): Collection { return $this->tags; }
    public function addTag(Tag $tag): static { if (!$this->tags->contains($tag)) { $this->tags->add($tag); } return $this; }
    public function clearTags(): static { $this->tags->clear(); return $this; }

    public function getVehicle(): ?Vehicle { return $this->vehicle; }
    public function setVehicle(?Vehicle $vehicle): static { $this->vehicle = $vehicle; return $this; }

    public function getRefuel(): ?Refuel { return $this->refuel; }
    public function setRefuel(?Refuel $refuel): static { $this->refuel = $refuel; return $this; }

    public function getInvestmentEntry(): ?InvestmentEntry { return $this->investmentEntry; }

    public function getImportBatch(): ?ImportBatch { return $this->importBatch; }
    public function getImportHash(): ?string { return $this->importHash; }
    public function setImported(ImportBatch $batch, string $hash): static { $this->importBatch = $batch; $this->importHash = $hash; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
