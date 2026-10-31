<?php

namespace App\Import;

use App\Util\Money;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Export CSV di Spendee: Date,Wallet,Type,"Category name",Amount,Currency,Note,Labels,Author.
 * Date in UTC (ISO 8601), uscite con importo negativo, etichette separate da virgola.
 */
final class SpendeeFormat implements ImportFormat
{
    private const HEADER = ['date', 'wallet', 'type', 'category name', 'amount', 'currency', 'note', 'labels'];

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function name(): string
    {
        return 'spendee';
    }

    public function label(): string
    {
        return 'Spendee';
    }

    public function supports(array $header): bool
    {
        $header = array_map(fn ($h) => mb_strtolower(trim($h)), $header);

        return !array_diff(self::HEADER, $header);
    }

    public function parse(array $row, int $line): ImportRow
    {
        $row = array_change_key_case($row, CASE_LOWER);
        $date = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, trim($row['date']))
            ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', trim($row['date']), new \DateTimeZone('UTC'));
        if (!$date) {
            throw new \InvalidArgumentException($this->translator->trans('data non valida "%value%"', ['%value%' => $row['date']]));
        }
        $date = $date->setTimezone(new \DateTimeZone('Europe/Rome'));

        $raw = trim($row['amount']);
        if (!is_numeric($raw)) {
            throw new \InvalidArgumentException($this->translator->trans('importo non valido "%value%"', ['%value%' => $raw]));
        }
        $amount = Money::parse(ltrim($raw, '-+'));
        if ((float) $amount == 0) {
            throw new \InvalidArgumentException($this->translator->trans('importo zero'));
        }

        $type = mb_strtolower(trim($row['type']));
        $kind = match (true) {
            str_starts_with($type, 'transfer') => 'transfer',
            $type === 'income' => 'income',
            $type === 'expense' => 'expense',
            default => (float) $raw < 0 ? 'expense' : 'income',
        };
        // Un trasferimento che resta spaiato diventa uscita o entrata secondo il segno.
        if ($kind === 'transfer') {
            $kind = 'transfer:'.((float) $raw < 0 ? 'out' : 'in');
        }

        $tags = array_values(array_filter(array_map('trim', explode(',', $row['labels'] ?? ''))));

        return new ImportRow(
            line: $line,
            date: $date,
            kind: $kind,
            amount: $amount,
            wallet: trim($row['wallet']) ?: 'Spendee',
            category: trim($row['category name']) ?: null,
            description: trim($row['note'] ?? ''),
            tags: $tags,
            currency: strtoupper(trim($row['currency'] ?? 'EUR')) ?: 'EUR',
            fingerprint: implode('|', [trim($row['date']), trim($row['wallet']), $type, trim($row['category name']), $raw, trim($row['note'] ?? '')]),
        );
    }

    public function finalize(array $rows): array
    {
        return TransferPairing::pair($rows);
    }
}
