<?php

namespace App\Entity;

use App\Repository\ImportBatchRepository;
use Doctrine\ORM\Mapping as ORM;

/** Un'importazione da file: serve a mostrarne lo storico e ad annullarla in un colpo solo. */
#[ORM\Entity(repositoryClass: ImportBatchRepository::class)]
class ImportBatch implements OwnedInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 255)]
    private string $filename;

    #[ORM\Column(length: 30)]
    private string $format;

    #[ORM\Column]
    private int $imported = 0;

    #[ORM\Column]
    private int $skipped = 0;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $firstDate = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastDate = null;

    /** Id di categorie, conti ed etichette creati dall'importazione: all'annullamento si tolgono se inutilizzati. */
    #[ORM\Column(type: 'json')]
    private array $created = ['categories' => [], 'accounts' => [], 'tags' => []];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, string $filename, string $format)
    {
        $this->user = $user;
        $this->filename = mb_substr($filename, 0, 255);
        $this->format = $format;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getFilename(): string { return $this->filename; }
    public function getFormat(): string { return $this->format; }

    public function getImported(): int { return $this->imported; }
    public function setImported(int $imported): static { $this->imported = $imported; return $this; }

    public function getSkipped(): int { return $this->skipped; }
    public function setSkipped(int $skipped): static { $this->skipped = $skipped; return $this; }

    public function getFirstDate(): ?\DateTimeImmutable { return $this->firstDate; }
    public function getLastDate(): ?\DateTimeImmutable { return $this->lastDate; }
    public function setRange(?\DateTimeImmutable $first, ?\DateTimeImmutable $last): static { $this->firstDate = $first; $this->lastDate = $last; return $this; }

    /** @return array{categories: list<int>, accounts: list<int>, tags: list<int>} */
    public function getCreated(): array { return $this->created + ['categories' => [], 'accounts' => [], 'tags' => []]; }
    public function setCreated(array $created): static { $this->created = $created; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
