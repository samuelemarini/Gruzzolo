<?php

namespace App\Entity;

use App\Repository\HoldingRepository;
use App\Util\Trans;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/** Un titolo del portafoglio investimenti (ETF, azione, crypto...). */
#[ORM\Entity(repositoryClass: HoldingRepository::class)]
class Holding implements OwnedInterface
{
    public const KINDS = ['etf' => 'ETF', 'stock' => 'Azione', 'crypto' => 'Crypto', 'bond' => 'Obbligazione', 'fund' => 'Fondo', 'other' => 'Altro'];
    public const KIND_ICONS = ['etf' => 'chart-line', 'stock' => 'building-2', 'crypto' => 'bitcoin', 'bond' => 'landmark', 'fund' => 'chart-pie', 'other' => 'trending-up'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 80)]
    private string $name = '';

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $symbol = null;

    /** Simbolo per il prezzo di mercato (es. SWDA.MI, BTC-EUR); vuoto = trovato dall'ISIN o dal simbolo. */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $quoteSymbol = null;

    #[ORM\Column(length: 20)]
    private string $kind = 'etf';

    #[ORM\Column(length: 7)]
    private string $color = '#7FD6FF';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $archived = false;

    /** Visibile fra i titoli ma fuori da valore, versato e rendimento del portafoglio. */
    #[ORM\Column(options: ['default' => false])]
    private bool $excludedFromReturns = false;

    /** @var Collection<int, InvestmentEntry> */
    #[ORM\OneToMany(mappedBy: 'holding', targetEntity: InvestmentEntry::class, cascade: ['remove'])]
    #[ORM\OrderBy(['date' => \SortDirection::Ascending, 'id' => \SortDirection::Ascending])]
    private Collection $entries;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user)
    {
        $this->user = $user;
        $this->entries = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = trim($name); return $this; }

    public function getSymbol(): ?string { return $this->symbol; }
    public function setSymbol(?string $symbol): static { $symbol = $symbol !== null ? trim($symbol) : ''; $this->symbol = $symbol !== '' ? $symbol : null; return $this; }

    public function getQuoteSymbol(): ?string { return $this->quoteSymbol; }
    public function setQuoteSymbol(?string $symbol): static { $symbol = strtoupper(trim((string) $symbol)); $this->quoteSymbol = $symbol !== '' ? $symbol : null; return $this; }

    public function getKind(): string { return $this->kind; }
    public function setKind(string $kind): static { $this->kind = array_key_exists($kind, self::KINDS) ? $kind : 'other'; return $this; }
    public function getKindLabel(): string { return isset(self::KINDS[$this->kind]) ? Trans::label(self::KINDS[$this->kind]) : $this->kind; }
    public function getIcon(): string { return self::KIND_ICONS[$this->kind] ?? 'trending-up'; }

    public function getColor(): string { return $this->color; }
    public function setColor(string $color): static { $this->color = $color; return $this; }

    public function getNote(): ?string { return $this->note; }
    public function setNote(?string $note): static { $this->note = $note !== null && trim($note) !== '' ? trim($note) : null; return $this; }

    public function isArchived(): bool { return $this->archived; }
    public function setArchived(bool $archived): static { $this->archived = $archived; return $this; }

    public function isExcludedFromReturns(): bool { return $this->excludedFromReturns; }
    public function setExcludedFromReturns(bool $excluded): static { $this->excludedFromReturns = $excluded; return $this; }

    /** @return Collection<int, InvestmentEntry> */
    public function getEntries(): Collection { return $this->entries; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
