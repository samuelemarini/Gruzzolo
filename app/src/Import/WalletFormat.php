<?php

namespace App\Import;

use App\Util\Money;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Export CSV di Wallet (BudgetBakers):
 * account;category;currency;amount;ref_currency_amount;type;payment_type;note;date;transfer;payee;labels
 * Importi positivi con il tipo localizzato ("Uscita"/"Entrata" o "Expense"/"Income"), date ISO in UTC.
 */
final class WalletFormat implements ImportFormat
{
    private const HEADER = ['account', 'category', 'currency', 'amount', 'type', 'note', 'date', 'transfer'];
    private const EXPENSE = ['uscita', 'uscite', 'spesa', 'expense', 'expenses'];
    private const INCOME = ['entrata', 'entrate', 'reddito', 'income'];

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function name(): string
    {
        return 'wallet';
    }

    public function label(): string
    {
        return 'Wallet (BudgetBakers)';
    }

    public function supports(array $header): bool
    {
        $header = array_map(fn ($h) => mb_strtolower(trim($h)), $header);

        return !array_diff(self::HEADER, $header) && in_array('payment_type', $header, true);
    }

    public function parse(array $row, int $line): ImportRow
    {
        $row = array_change_key_case($row, CASE_LOWER);
        try {
            $date = (new \DateTimeImmutable(trim($row['date'])))->setTimezone(new \DateTimeZone('Europe/Rome'));
        } catch (\Exception) {
            throw new \InvalidArgumentException($this->translator->trans('data non valida "%value%"', ['%value%' => $row['date']]));
        }

        $raw = str_replace(',', '.', trim($row['amount']));
        if (!is_numeric($raw)) {
            throw new \InvalidArgumentException($this->translator->trans('importo non valido "%value%"', ['%value%' => $row['amount']]));
        }
        $amount = Money::parse(ltrim($raw, '-+'));
        if ((float) $amount == 0) {
            throw new \InvalidArgumentException($this->translator->trans('importo zero'));
        }

        $type = mb_strtolower(trim($row['type']));
        $kind = match (true) {
            in_array($type, self::EXPENSE, true) => 'expense',
            in_array($type, self::INCOME, true) => 'income',
            default => (float) $raw < 0 ? 'expense' : 'income',
        };
        if (in_array(mb_strtolower(trim($row['transfer'] ?? '')), ['true', '1', 'yes', 'si', 'sì'], true)) {
            $kind = $kind === 'expense' ? 'transfer:out' : 'transfer:in';
        }

        $note = trim($row['note'] ?? '');
        $payee = trim($row['payee'] ?? '');
        $labels = preg_split('/[|,]/', $row['labels'] ?? '') ?: [];

        return new ImportRow(
            line: $line,
            date: $date,
            kind: $kind,
            amount: $amount,
            wallet: trim($row['account']) ?: 'Wallet',
            category: trim($row['category']) ?: null,
            // La nota e' la descrizione; se manca, il beneficiario.
            description: $note !== '' ? $note : $payee,
            tags: array_values(array_filter(array_map('trim', $labels))),
            currency: strtoupper(trim($row['currency'] ?? 'EUR')) ?: 'EUR',
            fingerprint: implode('|', [trim($row['date']), trim($row['account']), $type, trim($row['category']), $raw, $note, $payee]),
        );
    }

    public function finalize(array $rows): array
    {
        return TransferPairing::pair($rows);
    }
}
