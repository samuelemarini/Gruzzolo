<?php

namespace App\Entity;

use App\Repository\RefuelRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Dati del rifornimento attaccati a un movimento di carburante.
 *
 * Il totale e' l'importo del movimento, il veicolo e' quello del movimento: qui solo cio' che manca.
 * Per le auto elettriche "litri" sono kWh e il prezzo e' al kWh.
 */
#[ORM\Entity(repositoryClass: RefuelRepository::class)]
class Refuel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'refuel')]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private Movement $movement;

    #[ORM\Column]
    private int $odometer = 0;

    #[ORM\Column(type: 'decimal', precision: 8, scale: 2)]
    private string $liters = '0.00';

    #[ORM\Column(type: 'decimal', precision: 6, scale: 3)]
    private string $pricePerLiter = '0.000';

    #[ORM\Column(options: ['default' => true])]
    private bool $fullTank = true;

    /** Il rifornimento precedente non e' stato registrato: la media riparte da qui. */
    #[ORM\Column(options: ['default' => false])]
    private bool $missedPrevious = false;

    public function __construct(Movement $movement)
    {
        $this->movement = $movement;
        $movement->setRefuel($this);
    }

    public function getId(): ?int { return $this->id; }
    public function getMovement(): Movement { return $this->movement; }

    public function getOdometer(): int { return $this->odometer; }
    public function setOdometer(int $odometer): static { $this->odometer = $odometer; return $this; }

    public function getLiters(): string { return $this->liters; }
    public function setLiters(string $liters): static { $this->liters = $liters; return $this; }

    public function getPricePerLiter(): string { return $this->pricePerLiter; }
    public function setPricePerLiter(string $pricePerLiter): static { $this->pricePerLiter = $pricePerLiter; return $this; }

    public function isFullTank(): bool { return $this->fullTank; }
    public function setFullTank(bool $fullTank): static { $this->fullTank = $fullTank; return $this; }

    public function isMissedPrevious(): bool { return $this->missedPrevious; }
    public function setMissedPrevious(bool $missedPrevious): static { $this->missedPrevious = $missedPrevious; return $this; }
}
