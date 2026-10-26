<?php

namespace App\Entity;

use App\Repository\UserRepository;
use App\Service\Palette;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
#[ORM\UniqueConstraint(name: 'uniq_user_username', columns: ['username'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const THEMES = ['system' => 'Automatico', 'light' => 'Chiaro', 'dark' => 'Scuro'];

    /** Stile dell'interfaccia: superfici piene oppure "Liquid Glass" (vetro traslucido alla iOS 26/27). */
    public const STYLES = ['classic' => 'Classico', 'glass' => 'Liquid Glass'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $username = '';

    #[ORM\Column(length: 100)]
    private string $displayName = '';

    /** @var list<string> */
    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    private string $password = '';

    #[ORM\Column(length: 10, options: ['default' => 'system'])]
    private string $theme = 'system';

    #[ORM\Column(length: 10, options: ['default' => 'classic'])]
    private string $style = 'classic';

    /** Colore d'accento, chiave di Palette::ACCENTS. */
    #[ORM\Column(length: 10, options: ['default' => 'lime'])]
    private string $accent = 'lime';

    /** Colore dell'icona dell'app: chiave di Palette::ACCENTS, "accento" per seguirlo, null = originale (indaco). */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $iconColor = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Voci tolte dal patrimonio nella pagina Conti: id dei conti e "inv" per gli investimenti. */
    #[ORM\Column(nullable: true)]
    private ?array $netWorthExcluded = null;

    /** Stima di oggi degli investimenti dai prezzi di mercato (disattivabile dalla pagina Investimenti). */
    #[ORM\Column(options: ['default' => true])]
    private bool $liveInvestments = true;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getUsername(): string { return $this->username; }
    public function setUsername(string $username): static { $this->username = mb_strtolower(trim($username)); return $this; }

    public function getDisplayName(): string { return $this->displayName ?: $this->username; }
    public function setDisplayName(string $displayName): static { $this->displayName = trim($displayName); return $this; }

    public function getUserIdentifier(): string { return $this->username; }

    /** @return list<string> */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): static { $this->roles = array_values($roles); return $this; }

    public function isAdmin(): bool { return in_array('ROLE_ADMIN', $this->roles, true); }
    public function setAdmin(bool $admin): static
    {
        $this->roles = $admin ? ['ROLE_ADMIN'] : [];

        return $this;
    }

    public function getPassword(): string { return $this->password; }
    public function setPassword(string $password): static { $this->password = $password; return $this; }

    /** @param string $key id del conto oppure "inv" */
    public function isLiveInvestments(): bool { return $this->liveInvestments; }
    public function setLiveInvestments(bool $on): static { $this->liveInvestments = $on; return $this; }

    public function isInNetWorth(string $key): bool { return !in_array($key, $this->netWorthExcluded ?? [], true); }

    public function toggleNetWorth(string $key): static
    {
        $excluded = $this->netWorthExcluded ?? [];
        $this->netWorthExcluded = in_array($key, $excluded, true) ? array_values(array_diff($excluded, [$key])) : [...$excluded, $key];

        return $this;
    }

    public function getTheme(): string { return $this->theme; }
    public function setTheme(string $theme): static
    {
        $this->theme = array_key_exists($theme, self::THEMES) ? $theme : 'system';

        return $this;
    }

    public function getStyle(): string { return $this->style; }
    public function setStyle(string $style): static
    {
        $this->style = array_key_exists($style, self::STYLES) ? $style : 'classic';

        return $this;
    }

    public function getAccent(): string { return array_key_exists($this->accent, Palette::ACCENTS) ? $this->accent : 'lime'; }
    public function setAccent(string $accent): static
    {
        $this->accent = array_key_exists($accent, Palette::ACCENTS) ? $accent : 'lime';

        return $this;
    }

    public function getIconColor(): ?string { return $this->iconColor; }
    public function setIconColor(?string $color): static
    {
        $this->iconColor = $color === Palette::ICON_FOLLOWS_ACCENT || array_key_exists((string) $color, Palette::ACCENTS) ? $color : null;

        return $this;
    }

    public function isIconFollowingAccent(): bool { return $this->iconColor === Palette::ICON_FOLLOWS_ACCENT; }

    /** Colore effettivo dell'icona dell'app: chiave di Palette::ACCENTS oppure Palette::APP_ICON. */
    public function getIconAccent(): string
    {
        return match (true) {
            $this->isIconFollowingAccent() => $this->getAccent(),
            array_key_exists((string) $this->iconColor, Palette::ACCENTS) => $this->iconColor,
            default => Palette::APP_ICON,
        };
    }

    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): static { $this->active = $active; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function eraseCredentials(): void
    {
    }

    /** La sessione salva solo l'hash crc32 della password (raccomandazione Symfony 7.3+). */
    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0".self::class."\0password"] = hash('crc32c', $this->password);

        return $data;
    }
}
