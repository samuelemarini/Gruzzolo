<?php

namespace App\Service;

use App\Entity\Category;
use App\Entity\User;
use App\Repository\AccountRepository;
use App\Repository\CategoryRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Aggregati per Home e Report, visti dai conti considerati (tutti quelli accessibili o quelli scelti).
 * Un giroconto che esce da quei conti e' un'uscita, uno che arriva da fuori un'entrata; fra due conti
 * considerati e' solo uno spostamento e non conta. Un versamento negli investimenti esce dai conti.
 * Query SQL dirette: DQL non conosce YEAR()/MONTH() e qui servono solo numeri.
 */
final class ReportService
{
    /** Importo che entra nei conti considerati (:scope), altrimenti NULL. */
    private const INCOME = "CASE WHEN m.kind = 'income' OR (m.kind = 'transfer' AND m.to_account_id IN (:scope)
        AND (m.account_id IS NULL OR m.account_id NOT IN (:scope))) THEN m.amount END";

    /** Importo che esce dai conti considerati (:scope), altrimenti NULL. */
    private const EXPENSE = "CASE WHEN m.kind = 'expense' OR (m.kind = 'transfer' AND m.account_id IN (:scope)
        AND (m.to_account_id IS NULL OR m.to_account_id NOT IN (:scope))) THEN m.amount END";

    public function __construct(
        private readonly Connection $db,
        private readonly CategoryRepository $categories,
    ) {
    }

    /** @return array{income: float, expense: float, net: float, count: int, expenseCount: int, incomeCount: int} */
    public function summary(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to, array $accountIds = []): array
    {
        [$where, $params, $types] = $this->where($user, $from, $to, $accountIds);
        $income = self::INCOME;
        $expense = self::EXPENSE;
        $row = $this->db->fetchAssociative(<<<SQL
            SELECT COALESCE(SUM($income), 0) AS income, COALESCE(SUM($expense), 0) AS expense,
                   COUNT($income) AS income_count, COUNT($expense) AS expense_count
            FROM movement m WHERE $where
            SQL, $params, $types);
        $income = (float) $row['income'];
        $expense = (float) $row['expense'];

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
    public function byCategory(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to, string $kind = 'expense', array $accountIds = []): array
    {
        [$where, $params, $types] = $this->where($user, $from, $to, $accountIds);
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
                    'category' => $top, 'ids' => [], 'transfer' => $transfer,
                    'name' => $transfer ? 'Giroconti' : ($top?->getName() ?? 'Senza categoria'),
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
    public function monthly(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to, array $accountIds = []): array
    {
        $from = $from->modify('first day of this month');
        [$where, $params, $types] = $this->where($user, $from, $to, $accountIds);
        $income = self::INCOME;
        $expense = self::EXPENSE;
        $rows = $this->db->fetchAllAssociative(<<<SQL
            SELECT DATE_FORMAT(m.date, '%Y-%m') AS ym,
                   COALESCE(SUM($income), 0) AS income, COALESCE(SUM($expense), 0) AS expense
            FROM movement m WHERE $where
            GROUP BY ym
            SQL, $params, $types);
        $byMonth = array_column($rows, null, 'ym');

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
        $params['user'] = $user->getId();
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

    /** @return array{0: string, 1: array<string, mixed>, 2: array<string, mixed>} */
    private function where(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to, array $accountIds): array
    {
        // Conti propri e condivisi (le spese del conto comune entrano nei report di entrambi), ristretti a
        // quelli chiesti. Si prendono anche i giroconti che arrivano da fuori: sono entrate di questi conti.
        $where = '(m.account_id IN (:scope) OR m.to_account_id IN (:scope)) AND m.date BETWEEN :from AND :to';
        $params = ['scope' => $this->scope($user, $accountIds), 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')];

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
