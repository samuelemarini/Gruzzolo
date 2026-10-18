<?php

namespace App\Entity;

use App\Repository\VehicleRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: VehicleRepository::class)]
class Vehicle implements OwnedInterface
{
    public const KINDS = ['car' => 'Auto', 'motorbike' => 'Moto', 'scooter' => 'Scooter', 'van' => 'Furgone', 'other' => 'Altro'];
    public const KIND_ICONS = ['car' => 'car', 'motorbike' => 'motorbike', 'scooter' => 'scooter', 'van' => 'truck', 'other' => 'caravan'];
    public const FUELS = ['petrol' => 'Benzina', 'diesel' => 'Diesel', 'lpg' => 'GPL', 'methane' => 'Metano', 'hybrid' => 'Ibrida', 'electric' => 'Elettrica'];

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
    private string $kind = 'car';

    #[ORM\Column(length: 20)]
    private string $fuelType = 'petrol';

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $plate = null;

    #[ORM\Column(nullable: true)]
    private ?int $initialOdometer = null;

    /** Capacita' del serbatoio (litri, o kWh della batteria): serve per l'autonomia stimata. */
    #[ORM\Column(nullable: true)]
    private ?float $tankCapacity = null;

    #[ORM\Column(length: 7)]
    private string $color = '#6FDCCB';

    #[ORM\Column(options: ['default' => false])]
    private bool $archived = false;

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
    public function getIcon(): string { return self::KIND_ICONS[$this->kind] ?? 'car'; }

    public function getFuelType(): string { return $this->fuelType; }
    public function setFuelType(string $fuelType): static { $this->fuelType = array_key_exists($fuelType, self::FUELS) ? $fuelType : 'petrol'; return $this; }
    public function getFuelLabel(): string { return self::FUELS[$this->fuelType] ?? $this->fuelType; }
    public function isElectric(): bool { return $this->fuelType === 'electric'; }

    public function getPlate(): ?string { return $this->plate; }
    public function setPlate(?string $plate): static { $plate = $plate !== null ? strtoupper(preg_replace('/\s+/', '', $plate) ?? '') : ''; $this->plate = $plate !== '' ? $plate : null; return $this; }

    public function getInitialOdometer(): ?int { return $this->initialOdometer; }
    public function setInitialOdometer(?int $initialOdometer): static { $this->initialOdometer = $initialOdometer; return $this; }

    public function getTankCapacity(): ?float { return $this->tankCapacity; }
    public function setTankCapacity(?float $tankCapacity): static { $this->tankCapacity = $tankCapacity > 0 ? $tankCapacity : null; return $this; }

    public function getColor(): string { return $this->color; }
    public function setColor(string $color): static { $this->color = $color; return $this; }

    public function isArchived(): bool { return $this->archived; }
    public function setArchived(bool $archived): static { $this->archived = $archived; return $this; }
}
