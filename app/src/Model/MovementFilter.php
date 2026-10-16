<?php

namespace App\Model;

use App\Util\Param;
use App\Util\Money;
use Symfony\Component\HttpFoundation\Request;

/** Filtri della lista movimenti, letti dalla query string (cosi' i link restano condivisibili). */
final class MovementFilter
{
    public ?string $q = null;
    public ?\DateTimeImmutable $from = null;
    public ?\DateTimeImmutable $to = null;
    public ?string $kind = null;
    /** @var list<int> */
    public array $categories = [];
    /** @var list<int> */
    public array $tags = [];
    /** @var list<int> conti scelti; vuoto = tutti */
    public array $accounts = [];
    /** Conti scelti dall'utente (anche "tutti"), non il predefinito messo d'ufficio. */
    public bool $accountsChosen = false;
    public ?int $vehicle = null;
    public ?float $min = null;
    public ?float $max = null;
    /** 'month' | 'last-month' | 'year' | 'all' | 'custom' */
    public string $period = 'month';

    /** @param ?int $defaultAccount conto mostrato se nella richiesta non ne sono indicati (il principale) */
    public static function fromRequest(Request $request, ?int $defaultAccount = null): self
    {
        $f = new self();
        $query = $request->query;
        $f->q = trim((string) $query->get('q', '')) ?: null;
        $kind = (string) $query->get('kind', '');
        $f->kind = in_array($kind, ['expense', 'income', 'transfer'], true) ? $kind : null;
        $f->categories = self::ids($query->all()['cat'] ?? []);
        $f->tags = self::ids($query->all()['tag'] ?? []);
        [$f->accounts, $f->accountsChosen] = self::accountsFrom($request, $defaultAccount);
        $f->vehicle = Param::int($query, 'vehicle') ?: null;
        $f->min = self::number($query->get('min'));
        $f->max = self::number($query->get('max'));

        $today = new \DateTimeImmutable('today');
        $f->period = (string) $query->get('period', $f->q !== null || $f->tags ? 'all' : 'month');
        switch ($f->period) {
            case 'last-month':
                $f->from = $today->modify('first day of last month');
                $f->to = $today->modify('last day of last month');
                break;
            case 'year':
                $f->from = $today->setDate((int) $today->format('Y'), 1, 1);
                $f->to = $today->setDate((int) $today->format('Y'), 12, 31);
                break;
            case 'all':
                break;
            case 'custom':
                $f->from = self::date($query->get('from'));
                $f->to = self::date($query->get('to'));
                break;
            default:
                $f->period = 'month';
                $month = self::date($query->get('month').'-01') ?? $today;
                $f->from = $month->modify('first day of this month');
                $f->to = $month->modify('last day of this month');
        }

        return $f;
    }

    /**
     * Conti dalla query: ?account[]=4&account[]=20, oppure ?account=all per tutti; senza parametro il
     * predefinito. Valido anche per il Report.
     *
     * @return array{0: list<int>, 1: bool} id dei conti (vuoto = tutti) e se li ha scelti l'utente
     */
    public static function accountsFrom(Request $request, ?int $defaultAccount): array
    {
        $raw = $request->query->all()['account'] ?? null;
        if ($raw === null || $raw === '' || $raw === []) {
            return [$defaultAccount ? [$defaultAccount] : [], false];
        }

        return [self::ids($raw), true];
    }

    /** Parametro "account" per i link che devono mantenere la scelta dei conti (null = predefinito). */
    public static function accountsParam(array $accounts, bool $chosen): array|string|null
    {
        return $chosen ? ($accounts ?: 'all') : null;
    }

    /** Numero di filtri "avanzati" attivi, per il pallino sul bottone Filtri. */
    public function countAdvanced(): int
    {
        return ($this->kind ? 1 : 0) + count($this->categories) + count($this->tags) + ($this->accountsChosen ? max(1, count($this->accounts)) : 0)
            + ($this->vehicle ? 1 : 0) + ($this->min !== null ? 1 : 0) + ($this->max !== null ? 1 : 0)
            + ($this->period !== 'month' ? 1 : 0);
    }

    /** @return list<int> */
    private static function ids(mixed $value): array
    {
        $value = is_array($value) ? $value : [$value];

        return array_values(array_filter(array_map('intval', $value)));
    }

    private static function number(mixed $value): ?float
    {
        $value = Money::parse($value);

        return $value !== null ? (float) $value : null;
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $value);

        return $date ?: null;
    }
}
