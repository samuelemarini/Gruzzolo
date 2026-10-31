<?php

namespace App\Service;

use App\Entity\Category;
use App\Entity\Holding;
use App\Entity\User;
use App\Repository\AccountRepository;
use App\Repository\CategoryRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Aggregati per Home e Report, visti dai conti considerati (tutti quelli accessibili o quelli scelti).
 * Un giroconto che esce da quei conti e' un'uscita, uno che arriva da fuori un'entrata; fra due conti
 * considerati e' solo uno spostamento e non conta. Un versamento negli investimenti esce dai conti.
 * Gli investimenti si possono considerare come un conto in piu' ($investments): i versamenti nei titoli
 * sono entrate, i prelievi uscite, e un versamento da un conto considerato e' solo uno spostamento.
 * Query SQL dirette: DQL non conosce YEAR()/MONTH() e qui servono solo numeri.
 */
final class ReportService
{
    /** Importo che entra nei conti considerati (:scope), altrimenti NULL. */
    private const INCOME = "CASE WHEN m.kind = 'income' OR (m.kind = 'transfer' AND m.to_account_id IN (:scope)
        AND (m.account_id IS NULL OR m.account_id NOT IN (:scope))) THEN m.amount END";

    /** Importo che esce dai conti considerati (:scope), altrimenti NULL. */
    private const EXPENSE = "CASE WHEN m.kind = 'expense' OR (m.kind = 'transfer' AND m.account_id IN (:scope)
        AND (CASE WHEN m.to_account_id IS NULL THEN :inv = 0 ELSE m.to_account_id NOT IN (:scope) END)) THEN m.amount END";

    /**
     * Versamenti/prelievi nei titoli dell'utente nel periodo, tolti quelli partiti da un conto considerato
     * (con gli investimenti nel perimetro sono spostamenti interni).
     */
    private const ENTRIES = 'FROM investment_entry e JOIN holding h ON h.id = e.holding_id LEFT JOIN movement mv ON mv.id = e.movement_id
        WHERE h.user_id = :user AND e.date BETWEEN :from AND :to AND e.contribution <> 0
        AND (mv.id IS NULL OR mv.account_id NOT IN (:scope))';

    public function __construct(
        private readonly Connection $db,
        private readonly CategoryRepository $categories,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /** @return array{income: float, expense: float, net: float, count: int, expenseCount: int, incomeCount: int} */
    public function summary(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to, array $accountIds = [], bool $investments = false): array
    {
        [$where, $params, $types] = $this->where($user, $from, $to, $accountIds, $investments);
        $income = self::INCOME;
        $expense = self::EXPENSE;
        $row = $this->db->fetchAssociative(<<<SQL
            SELECT COALESCE(SUM($income), 0) AS income, COALESCE(SUM($expense), 0) AS expense,
                   COUNT($income) AS income_count, COUNT($expense) AS expense_count
            FROM movement m WHERE $where
            SQL, $params, $types);
        if ($investments) {
            $entries = self::ENTRIES;
            $inv = $this->db->fetchAssociative(<<<SQL
                SELECT COALESCE(SUM(CASE WHEN e.contribution > 0 THEN e.contribution END), 0) AS income,
                       COALESCE(SUM(CASE WHEN e.contribution < 0 THEN -e.contribution END), 0) AS expense,
                       COUNT(CASE WHEN e.contribution > 0 THEN 1 END) AS income_count, COUNT(CASE WHEN e.contribution < 0 THEN 1 END) AS expense_count
                $entries
                SQL, $params, $types);
            foreach (['income', 'expense', 'income_count', 'expense_count'] as $key) {
                $row[$key] += $inv[$key];
            }
        }
        $income = round((float) $row['income'], 2);
        $expense = round((float) $row['expense'], 2);

        return [
            'income' => $income, 'expense' => $expense, 'net' => round($income - $expense, 2),
            'incomeCount' => (int) $row['income_count'], 'expenseCount' => (int) $row['expense_count'],
            'count' => (int) $row['income_count'] + (int) $row['expense_count'],
        ];
    }

    /**
     * Totali per categoria principale (le sottocategorie confluiscono nel padre), dal piu' grande.
     *
     * @return list<array{category: ?Category, name: string, icon: string, color: string, total: float, count: int, pct: float, children: list<array{category: Category, total: float, count: int}>}>
     */
    public function byCategory(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to, string $kind = 'expense', array $accountIds = [], bool $investments = false): array
    {
        [$where, $params, $types] = $this->where($user, $from, $to, $accountIds, $investments);
        $amount = $kind === Category::INCOME ? self::INCOME : self::EXPENSE;
        $rows = $this->db->fetchAllAssociative(<<<SQL
            SELECT m.category_id, c.parent_id, m.kind = 'transfer' AS transfer, SUM($amount) AS total, COUNT(*) AS n
            FROM movement m LEFT JOIN category c ON c.id = m.category_id
            WHERE $where AND ($amount) IS NOT NULL
            GROUP BY m.category_id, c.parent_id, transfer
            SQL, $params, $types);

        $ids = array_filter(array_merge(array_column($rows, 'category_id'), array_column($rows, 'parent_id')));
        $byId = [];
        foreach ($ids ? $this->categories->findBy(['id' => array_unique($ids)]) : [] as $category) {
            $byId[$category->getId()] = $category;
        }

        // Sul conto condiviso ognuno usa le sue categorie: quelle con lo stesso nome finiscono insieme,
        // rappresentate da quella dell'utente se c'e' (icona, colore, link).
        $groups = [];
        $grand = 0.0;
        foreach ($rows as $row) {
            $total = (float) $row['total'];
            $grand += $total;
            $topId = $row['parent_id'] ?? $row['category_id'];
            $top = $topId ? ($byId[(int) $topId] ?? null) : null;
            $transfer = (bool) $row['transfer'];
            $key = $transfer ? "\0giroconti" : ($top ? mb_strtolower(trim($top->getName())) : '');
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'category' => $top, 'ids' => [], 'transfer' => $transfer, 'holding' => null,
                    'name' => $transfer ? $this->translator->trans('Giroconti') : ($top?->getName() ?? $this->translator->trans('Senza categoria')),
                    'icon' => $transfer ? 'arrow-left-right' : ($top?->getIcon() ?? 'circle-ellipsis'),
                    'color' => $transfer ? '#D9B48F' : ($top?->getColor() ?? '#A3A59F'),
                    'total' => 0.0, 'count' => 0, 'pct' => 0.0, 'children' => [],
                ];
            }
            if ($top && $top->getUser() === $user && $groups[$key]['category']?->getUser() !== $user) {
                $groups[$key]['category'] = $top;
                $groups[$key]['icon'] = $top->getIcon();
                $groups[$key]['color'] = $top->getColor();
            }
            if ($top && !in_array($top->getId(), $groups[$key]['ids'], true)) {
                $groups[$key]['ids'][] = $top->getId();
            }
            $groups[$key]['total'] += $total;
            $groups[$key]['count'] += (int) $row['n'];
            if ($row['parent_id'] !== null && isset($byId[(int) $row['category_id']])) {
                $groups[$key]['children'][] = ['category' => $byId[(int) $row['category_id']], 'total' => $total, 'count' => (int) $row['n']];
            }
        }
        // Investimenti: una voce per titolo (versamenti fra le entrate, prelievi fra le uscite).
        if ($investments) {
            $entries = self::ENTRIES;
            $sign = $kind === Category::INCOME ? '>' : '<';
            foreach ($this->db->fetchAllAssociative(<<<SQL
                SELECT h.id, h.name, h.kind, h.color, SUM(ABS(e.contribution)) AS total, COUNT(*) AS n
                $entries AND e.contribution $sign 0
                GROUP BY h.id, h.name, h.kind, h.color
                SQL, $params, $types) as $row) {
                $grand += (float) $row['total'];
                $groups["\0titolo".$row['id']] = [
                    'category' => null, 'ids' => [], 'transfer' => false, 'holding' => (int) $row['id'],
                    'name' => $row['name'], 'icon' => Holding::KIND_ICONS[$row['kind']] ?? 'trending-up', 'color' => $row['color'],
                    'total' => (float) $row['total'], 'count' => (int) $row['n'], 'pct' => 0.0, 'children' => [],
                ];
            }
        }
        foreach ($groups as &$group) {
            $group['pct'] = $grand > 0 ? $group['total'] / $grand * 100 : 0;
            usort($group['children'], fn ($a, $b) => $b['total'] <=> $a['total']);
        }
        unset($group);
        $groups = array_values($groups);
        usort($groups, fn ($a, $b) => $b['total'] <=> $a['total']);

        return $groups;
    }

    /**
     * Entrate e uscite mese per mese, mesi senza movimenti compresi.
     *
     * @return list<array{month: \DateTimeImmutable, income: float, expense: float}>
     */
    public function monthly(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to, array $accountIds = [], bool $investments = false): array
    {
        $from = $from->modify('first day of this month');
        [$where, $params, $types] = $this->where($user, $from, $to, $accountIds, $investments);
        $income = self::INCOME;
        $expense = self::EXPENSE;
        $rows = $this->db->fetchAllAssociative(<<<SQL
            SELECT DATE_FORMAT(m.date, '%Y-%m') AS ym,
                   COALESCE(SUM($income), 0) AS income, COALESCE(SUM($expense), 0) AS expense
            FROM movement m WHERE $where
            GROUP BY ym
            SQL, $params, $types);
        if ($investments) {
            $entries = self::ENTRIES;
            $rows = array_merge($rows, $this->db->fetchAllAssociative(<<<SQL
                SELECT DATE_FORMAT(e.date, '%Y-%m') AS ym,
                       COALESCE(SUM(CASE WHEN e.contribution > 0 THEN e.contribution END), 0) AS income,
                       COALESCE(SUM(CASE WHEN e.contribution < 0 THEN -e.contribution END), 0) AS expense
                $entries
                GROUP BY ym
                SQL, $params, $types));
        }
        $byMonth = [];
        foreach ($rows as $row) {
            $byMonth[$row['ym']]['income'] = ($byMonth[$row['ym']]['income'] ?? 0) + (float) $row['income'];
            $byMonth[$row['ym']]['expense'] = ($byMonth[$row['ym']]['expense'] ?? 0) + (float) $row['expense'];
        }

        $out = [];
        for ($month = $from; $month <= $to; $month = $month->modify('+1 month')) {
            $row = $byMonth[$month->format('Y-m')] ?? null;
            $out[] = ['month' => $month, 'income' => (float) ($row['income'] ?? 0), 'expense' => (float) ($row['expense'] ?? 0)];
        }

        return $out;
    }

    /**
     * Uscite per etichetta nel periodo.
     *
     * @return list<array{id: int, name: string, title: ?string, color: string, expense: float, income: float, count: int}>
     */
    public function byTag(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to, array $accountIds = []): array
    {
        [$where, $params, $types] = $this->where($user, $from, $to, $accountIds);
        $rows = $this->db->fetchAllAssociative(<<<SQL
            SELECT t.id, t.name, t.title, t.color,
                   COALESCE(SUM(CASE WHEN m.kind = 'expense' THEN m.amount END), 0) AS expense,
                   COALESCE(SUM(CASE WHEN m.kind = 'income' THEN m.amount END), 0) AS income,
                   COUNT(*) AS n
            FROM movement m JOIN movement_tag mt ON mt.movement_id = m.id JOIN tag t ON t.id = mt.tag_id
            WHERE $where AND m.kind IN ('income', 'expense') AND t.user_id = :user
            GROUP BY t.id, t.name, t.title, t.color
            ORDER BY expense DESC
            SQL, $params, $types);

        return array_map(fn ($r) => [
            'id' => (int) $r['id'], 'name' => $r['name'], 'title' => $r['title'], 'color' => $r['color'],
            'expense' => (float) $r['expense'], 'income' => (float) $r['income'], 'count' => (int) $r['n'],
        ], $rows);
    }

    /** @return array{amount: float, description: string, category: ?string}|null */
    public function biggestExpense(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to, array $accountIds = []): ?array
    {
        [$where, $params, $types] = $this->where($user, $from, $to, $accountIds);
        $row = $this->db->fetchAssociative(<<<SQL
            SELECT m.amount, m.description, c.name AS category
            FROM movement m LEFT JOIN category c ON c.id = m.category_id
            WHERE $where AND m.kind = 'expense' ORDER BY m.amount DESC LIMIT 1
            SQL, $params, $types);

        return $row ? ['amount' => (float) $row['amount'], 'description' => $row['description'], 'category' => $row['category']] : null;
    }

    /**
     * Investimenti nel periodo: valore a inizio e fine (ultimo valore noto di ogni titolo), versamenti netti
     * e rendimento = variazione del valore al netto dei versamenti, senza i titoli esclusi dal rendimento.
     * Null se l'utente non ha titoli conteggiati.
     *
     * @return array{start: float, end: float, contributions: float, gain: float, pct: ?float}|null
     */
    public function investmentPeriod(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): ?array
    {
        $valueAt = fn (string $date) => (float) $this->db->fetchOne(<<<'SQL'
            SELECT COALESCE(SUM((SELECT e.value FROM investment_entry e WHERE e.holding_id = h.id AND e.date <= :d
                                 ORDER BY e.date DESC, e.id DESC LIMIT 1)), 0)
            FROM holding h WHERE h.user_id = :user AND h.excluded_from_returns = 0
            SQL, ['d' => $date, 'user' => $user->getId()]);
        if (!$this->db->fetchOne('SELECT 1 FROM holding WHERE user_id = ? AND excluded_from_returns = 0 LIMIT 1', [$user->getId()])) {
            return null;
        }
        $start = $valueAt($from->modify('-1 day')->format('Y-m-d'));
        $end = $valueAt($to->format('Y-m-d'));
        $contributions = (float) $this->db->fetchOne(<<<'SQL'
            SELECT COALESCE(SUM(e.contribution), 0) FROM investment_entry e JOIN holding h ON h.id = e.holding_id
            WHERE h.user_id = :user AND h.excluded_from_returns = 0 AND e.date BETWEEN :from AND :to
            SQL, ['user' => $user->getId(), 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')]);
        $gain = $end - $start - $contributions;
        $base = $start + max($contributions, 0);

        return ['start' => round($start, 2), 'end' => round($end, 2), 'contributions' => round($contributions, 2),
            'gain' => round($gain, 2), 'pct' => $base > 0 ? $gain / $base * 100 : null];
    }

    /** @return array{0: string, 1: array<string, mixed>, 2: array<string, mixed>} */
    private function where(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to, array $accountIds, bool $investments = false): array
    {
        // Conti propri e condivisi (le spese del conto comune entrano nei report di entrambi), ristretti a
        // quelli chiesti. Si prendono anche i giroconti che arrivano da fuori: sono entrate di questi conti.
        $where = '(m.account_id IN (:scope) OR m.to_account_id IN (:scope)) AND m.date BETWEEN :from AND :to';
        $params = ['scope' => $this->scope($user, $accountIds), 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'),
            'inv' => (int) $investments, 'user' => $user->getId()];

        return [$where, $params, ['scope' => ArrayParameterType::INTEGER]];
    }

    /** @return non-empty-list<int> id dei conti considerati: gli accessibili, eventualmente solo quelli chiesti */
    private function scope(User $user, array $accountIds): array
    {
        $ids = array_map('intval', $this->db->fetchFirstColumn(AccountRepository::ACCESSIBLE_SQL, ['user' => $user->getId()]));
        if ($accountIds) {
            $ids = array_values(array_intersect($ids, array_map('intval', $accountIds)));
        }

        return $ids ?: [0];
    }
}
