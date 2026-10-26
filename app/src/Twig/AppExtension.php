<?php

namespace App\Twig;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

final class AppExtension
{
    private const MINUS = "\u{2212}";

    /** @var array<string, string> */
    private array $icons = [];

    public function __construct(
        #[Autowire('%kernel.project_dir%/resources/icons')]
        private readonly string $iconDir,
    ) {
    }

    /** @return array<string, string> */
    #[AsTwigFunction('palette_colors')]
    public function paletteColors(): array
    {
        return \App\Service\Palette::COLORS;
    }

    /** @return array<string, array{string, string, string}> */
    #[AsTwigFunction('palette_accents')]
    public function paletteAccents(): array
    {
        return \App\Service\Palette::ACCENTS;
    }

    /** Icona Lucide inline: eredita il colore del testo (currentColor). */
    #[AsTwigFunction('icon', isSafe: ['html'])]
    public function icon(string $name, int $size = 20, string $class = '', ?string $label = null): string
    {
        if (!isset($this->icons[$name])) {
            $file = $this->iconDir.'/'.basename($name).'.svg';
            $svg = is_file($file) ? (string) file_get_contents($file) : (string) file_get_contents($this->iconDir.'/circle-ellipsis.svg');
            $svg = preg_replace('/<!--.*?-->\s*/s', '', $svg) ?? '';
            // Solo sul tag <svg>: rect e circle interni hanno i loro width/height.
            $svg = preg_replace_callback('/<svg\b[^>]*>/', fn ($m) => preg_replace('/\s+(class|width|height)="[^"]*"/', '', $m[0]), $svg) ?? '';
            $this->icons[$name] = preg_replace('/\s*\n\s*/', ' ', trim($svg)) ?? '';
        }
        $a11y = $label !== null
            ? sprintf('role="img" aria-label="%s"', htmlspecialchars($label))
            : 'aria-hidden="true" focusable="false"';

        return preg_replace('/^<svg/', sprintf('<svg width="%d" height="%d" class="icon %s" %s', $size, $size, htmlspecialchars($class), $a11y), $this->icons[$name]) ?? '';
    }

    /** 1247.8 → "1.247,80 €"; con $signed "+1.247,80 €" / "−1.247,80 €". */
    #[AsTwigFilter('money')]
    public function money(float|int|string|null $value, bool $signed = false, int $decimals = 2): string
    {
        $value = (float) $value;
        $text = number_format(abs($value), $decimals, ',', '.')."\u{00A0}€";
        if ($value < 0 && round($value, $decimals) != 0) {
            return self::MINUS.$text;
        }

        return $signed && round($value, $decimals) != 0 ? '+'.$text : $text;
    }

    /** Importo di un movimento visto dall'utente: le uscite col meno, le entrate col piu'. */
    #[AsTwigFilter('movement_amount')]
    public function movementAmount(\App\Entity\Movement $movement): string
    {
        return match ($movement->getKind()) {
            'income' => '+'.$this->money($movement->getAmount()),
            'expense' => self::MINUS.$this->money($movement->getAmount()),
            default => $this->money($movement->getAmount()),
        };
    }

    #[AsTwigFilter('num')]
    public function num(float|int|string|null $value, int $decimals = 0): string
    {
        return number_format((float) $value, $decimals, ',', '.');
    }

    /** Valore per un <input> modificabile: "1247,80" (virgola, niente migliaia). */
    #[AsTwigFilter('input_num')]
    public function inputNum(float|int|string|null $value, int $decimals = 2): string
    {
        return $value === null || $value === '' ? '' : number_format((float) $value, $decimals, ',', '');
    }

    #[AsTwigFilter('pct')]
    public function pct(?float $value, int $decimals = 0, bool $signed = false): string
    {
        if ($value === null) {
            return '—';
        }
        $text = number_format(abs($value), $decimals, ',', '.').'%';
        if ($value < 0 && round($value, $decimals) != 0) {
            return self::MINUS.$text;
        }

        return $signed && round($value, $decimals) != 0 ? '+'.$text : $text;
    }

    /** Date in italiano con i pattern ICU: "EEEE d MMMM", "MMM yyyy"... */
    #[AsTwigFilter('it_date')]
    public function itDate(?\DateTimeInterface $date, string $pattern = 'd MMM yyyy'): string
    {
        if ($date === null) {
            return '';
        }
        $formatter = new \IntlDateFormatter('it_IT', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'Europe/Rome', null, $pattern);

        return (string) $formatter->format($date);
    }

    /** "Ottobre 2026" */
    #[AsTwigFilter('month_label')]
    public function monthLabel(\DateTimeInterface $date): string
    {
        return mb_convert_case($this->itDate($date, 'LLLL yyyy'), MB_CASE_TITLE);
    }

    /** Intestazione dei gruppi per giorno: "Oggi · venerdì 16 ott", "Ieri · ...", "Mercoledì 14 ott". */
    #[AsTwigFilter('day_label')]
    public function dayLabel(\DateTimeInterface $date): string
    {
        $today = new \DateTimeImmutable('today');
        $day = \DateTimeImmutable::createFromInterface($date)->setTime(0, 0);
        $withYear = $day->format('Y') !== $today->format('Y');
        $text = $this->itDate($day, $withYear ? 'EEEE d MMM yyyy' : 'EEEE d MMM');

        return match ((int) $today->diff($day)->format('%r%a')) {
            0 => 'Oggi · '.$text,
            -1 => 'Ieri · '.$text,
            default => mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1),
        };
    }

    /** "oggi", "ieri", "14 ott" per le righe compatte. */
    #[AsTwigFilter('short_day')]
    public function shortDay(\DateTimeInterface $date): string
    {
        $today = new \DateTimeImmutable('today');
        $day = \DateTimeImmutable::createFromInterface($date)->setTime(0, 0);

        return match ((int) $today->diff($day)->format('%r%a')) {
            0 => 'Oggi',
            -1 => 'Ieri',
            default => $this->itDate($day, $day->format('Y') === $today->format('Y') ? 'd MMM' : 'd MMM yyyy'),
        };
    }
}
