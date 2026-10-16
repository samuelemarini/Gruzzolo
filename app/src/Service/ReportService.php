<?php

namespace App\Service;

use App\Entity\Category;
use App\Entity\User;
use App\Repository\CategoryRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Aggregati per Home e Report. Contano solo entrate e uscite: i giroconti spostano soldi, non li spendono.
 * Query SQL dirette: DQL non conosce YEAR()/MONTH() e qui servono solo numeri.
 */
final class ReportService
{
    public function __construct(
        private readonly Connection $db,
        private readonly CategoryRepository $categories,
    ) {
    }

    /** @return array{income: float, expense: float, net: float, count: int, expenseCount: int, incomeCount: int} */
    public function summary(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to, array $accountIds = []): array
    {
        [$where, $params, $types] = $this->where($user, $from, $to, $accountIds);
        $row = $this->db->fetchAssociative(<<<SQL
            SELECT COALESCE(SUM(CASE WHEN kind = 'income' THEN amount END), 0) AS income,
                   COALESCE(SUM(CASE WHEN kind = 'expense' THEN amount END), 0) AS expense,
                   SUM(kind = 'income') AS income_count, SUM(kind = 'expense') AS expense_count
            FROM movement m WHERE $where AND kind IN ('income', 'expense')
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
        $params['kind'] = $kind;
        $rows = $this->db->fetchAllAssociative(<<<SQL
            SELECT m.category_id, c.parent_id, SUM(m.amount) AS total, COUNT(*) AS n
            FROM movement m LEFT JOIN category c ON c.id = m.category_id
            WHERE $where AND m.kind = :kind
            GROUP BY m.category_id, c.parent_id
            SQL, $params, $types);

        $byId = [];
        foreach ($this->categories->findBy(['user' => $user]) as $category) {
            $byId[$category->getId()] = $category;
        }

        $groups = [];
        $grand = 0.0;
        foreach ($rows as $row) {
            $total = (float) $row['total'];
            $grand += $total;
            $topId = $row['parent_id'] ?? $row['category_id'];
            $key = $topId ?? 0;
            if (!isset($groups[$key])) {
                $top = $topId ? ($byId[(int) $topId] ?? null) : null;
                $groups[$key] = [
                    'category' => $top,
                    'name' => $top?->getName() ?? 'Senza categoria',
                    'icon' => $top?->getIcon() ?? 'circle-ellipsis',
                    'color' => $top?->getColor() ?? '#A3A59F',
                    'total' => 0.0, 'count' => 0, 'pct' => 0.0, 'children' => [],
                ];
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
        $rows = $this->db->fetchAllAssociative(<<<SQL
            SELECT DATE_FORMAT(m.date, '%Y-%m') AS ym,
                   COALESCE(SUM(CASE WHEN kind = 'income' THEN amount END), 0) AS income,
                   COALESCE(SUM(CASE WHEN kind = 'expense' THEN amount END), 0) AS expense
            FROM movement m WHERE $where AND kind IN ('income', 'expense')
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
    public function byTag(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        [$where, $params, $types] = $this->where($user, $from, $to, []);
        $rows = $this->db->fetchAllAssociative(<<<SQL
            SELECT t.id, t.name, t.title, t.color,
                   COALESCE(SUM(CASE WHEN m.kind = 'expense' THEN m.amount END), 0) AS expense,
                   COALESCE(SUM(CASE WHEN m.kind = 'income' THEN m.amount END), 0) AS income,
                   COUNT(*) AS n
            FROM movement m JOIN movement_tag mt ON mt.movement_id = m.id JOIN tag t ON t.id = mt.tag_id
            WHERE $where AND m.kind IN ('income', 'expense')
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
        $where = 'm.user_id = :user AND m.date BETWEEN :from AND :to';
        $params = ['user' => $user->getId(), 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')];
        $types = [];
        if ($accountIds) {
            $where .= ' AND m.account_id IN (:accounts)';
            $params['accounts'] = $accountIds;
            $types['accounts'] = ArrayParameterType::INTEGER;
        }

        return [$where, $params, $types];
    }
}
