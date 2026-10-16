<?php

namespace App\Entity;

use App\Repository\InvestmentEntryRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Una "fotografia" di un titolo: quanto ho versato (o ritirato, negativo) e quanto vale dopo.
 *
 * Rendimento = ultimo valore - somma dei versamenti. Esempio: 1.000 versati (valore 1.000),
 * il mese dopo +50 versati e valore 1.100 → versati 1.050, rendimento +50.
 */
#[ORM\Entity(repositoryClass: InvestmentEntryRepository::class)]
class InvestmentEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'entries')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Holding $holding;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $date;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 2, options: ['default' => '0.00'])]
    private string $contribution = '0.00';

    #[ORM\Column(type: 'decimal', precision: 14, scale: 2)]
    private string $value = '0.00';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $note = null;

    /** Giroconto dal conto da cui sono usciti i soldi del versamento, se indicato. */
    #[ORM\OneToOne(inversedBy: 'investmentEntry')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Movement $movement = null;

    public function __construct(Holding $holding)
    {
        $this->holding = $holding;
        $this->date = new \DateTimeImmutable('today');
    }

    public function getId(): ?int { return $this->id; }
    public function getHolding(): Holding { return $this->holding; }

    public function getDate(): \DateTimeImmutable { return $this->date; }
    public function setDate(\DateTimeImmutable $date): static { $this->date = $date; return $this; }

    public function getContribution(): string { return $this->contribution; }
    public function setContribution(string $contribution): static { $this->contribution = $contribution; return $this; }

    public function getValue(): string { return $this->value; }
    public function setValue(string $value): static { $this->value = $value; return $this; }

    public function getNote(): ?string { return $this->note; }
    public function setNote(?string $note): static { $this->note = $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null; return $this; }

    public function getMovement(): ?Movement { return $this->movement; }
    public function setMovement(?Movement $movement): static { $this->movement = $movement; return $this; }
}
