<?php

namespace App\Import;

use App\Util\Money;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Export CSV di Money Manager (Realbyte), in italiano o in inglese:
 * Periodo;Conto;Categoria;Sotto-categoria;Nota;EUR;Guadagni/Spese;Descrizione;Importo;Valuta;Conto
 * Date gg/mm/aaaa, importi con la virgola (le spese vecchie a volte col segno meno). Le rettifiche
 * del saldo ("Decremento/Incremento Saldo") non sono movimenti e si scartano.
 */
final class MoneyManagerFormat implements ImportFormat
{
    private const DATE = ['periodo', 'period', 'data', 'date'];
    private const CATEGORY = ['categoria', 'category'];
    private const TYPE = ['guadagni/spese', 'entrate/spese', 'income/expense', 'income/expenses'];
    private const AMOUNT = ['importo', 'amount'];
    private const EXPENSE = ['spese', 'spesa', 'uscite', 'expense', 'expenses', 'exp.'];
    private const INCOME = ['in', 'entrate', 'entrata', 'guadagni', 'income'];

    /** Lettere accentate perse in qualche export (diventate U+FFFD). */
    private const MOJIBAKE = ["Caff\u{FFFD}" => 'Caffè', "caff\u{FFFD}" => 'caffè', "Pap\u{FFFD}" => 'Papà', "pap\u{FFFD}" => 'papà',
        "Mos\u{FFFD}" => 'Mosè', "brul\u{FFFD}" => 'brulé', "d\u{FFFD}orsay" => 'd’Orsay', "Sant\u{FFFD}" => 'Sant’', "\u{FFFD}" => '’'];

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function name(): string
    {
        return 'moneymanager';
    }

    public function label(): string
    {
        return 'Money Manager';
    }

    public function supports(array $header): bool
    {
        $header = array_map(fn ($h) => mb_strtolower(trim($h)), $header);

        return (bool) array_intersect(self::DATE, $header) && (bool) array_intersect(self::CATEGORY, $header)
            && (bool) array_intersect(self::TYPE, $header) && (bool) array_intersect(self::AMOUNT, $header);
    }

    public function parse(array $row, int $line): ImportRow
    {
        $row = array_change_key_case($row, CASE_LOWER);
        $get = fn (array $names) => trim((string) ($row[current(array_intersect($names, array_keys($row))) ?: ''] ?? ''));

        $rawDate = $get(self::DATE);
        $date = null;
        foreach (['!d/m/Y', '!d/m/Y H:i', '!d/m/Y H:i:s', '!Y-m-d', '!Y-m-d H:i:s'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $rawDate) ?: null;
            if ($date) {
                break;
            }
        }
        if (!$date) {
            throw new \InvalidArgumentException($this->translator->trans('data non valida "%value%"', ['%value%' => $rawDate]));
        }

        $type = mb_strtolower($get(self::TYPE));
        if (str_contains($type, 'saldo') || str_contains($type, 'balance')) {
            throw new \InvalidArgumentException($this->translator->trans('rettifica del saldo, non è un movimento'));
        }

        $raw = $get(self::AMOUNT);
        // "1.234,56" all'italiana, oppure "1234.56" negli export in inglese.
        $number = str_contains($raw, ',') ? str_replace(['.', ','], ['', '.'], $raw) : str_replace(' ', '', $raw);
        if (!is_numeric($number)) {
            throw new \InvalidArgumentException($this->translator->trans('importo non valido "%value%"', ['%value%' => $raw]));
        }
        $amount = Money::parse(ltrim($number, '-+'));
        if ((float) $amount == 0) {
            throw new \InvalidArgumentException($this->translator->trans('importo zero'));
        }
        $kind = match (true) {
            in_array($type, self::EXPENSE, true) => 'expense',
            in_array($type, self::INCOME, true) => 'income',
            default => (float) $number < 0 ? 'expense' : 'income',
        };

        $category = $this->fix($get(self::CATEGORY));
        $sub = $this->fix($get(['sotto-categoria', 'sottocategoria', 'subcategory']));
        $note = $this->fix($get(['nota', 'note']));
        $extra = $this->fix($get(['descrizione', 'description']));
        $description = trim($note.($extra !== '' && $extra !== $note ? ' — '.$extra : ''));
        if ($description === '' && $sub !== '') {
            $description = $sub;
        }
        $wallet = $get(['conto', 'conti', 'account', 'accounts']);

        return new ImportRow(
            line: $line,
            date: $date,
            kind: $kind,
            amount: $amount,
            wallet: $wallet !== '' ? $wallet : 'Money Manager',
            category: $category !== '' ? $category : null,
            description: $description,
            tags: [],
            currency: strtoupper($get(['valuta', 'currency'])) ?: 'EUR',
            fingerprint: implode('|', [$rawDate, $wallet, $type, $category, $sub, $raw, $note, $extra]),
        );
    }

    public function finalize(array $rows): array
    {
        return $rows;
    }

    private function fix(string $text): string
    {
        return str_contains($text, "\u{FFFD}") ? strtr($text, self::MOJIBAKE) : $text;
    }
}
