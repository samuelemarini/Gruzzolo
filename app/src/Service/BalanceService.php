<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\User;
use Doctrine\DBAL\Connection;

/** Saldi dei conti: saldo iniziale + entrate - uscite +/- giroconti. */
final class BalanceService
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array<int, float> saldo per id conto */
    public function balances(User $user, ?\DateTimeImmutable $until = null): array
    {
        $until ??= new \DateTimeImmutable('today');
        $rows = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT a.id, a.initial_balance
                + COALESCE((SELECT SUM(CASE m.kind WHEN 'income' THEN m.amount ELSE -m.amount END)
                            FROM movement m WHERE m.account_id = a.id AND m.date <= :until), 0)
                + COALESCE((SELECT SUM(t.amount) FROM movement t
                            WHERE t.to_account_id = a.id AND t.kind = 'transfer' AND t.date <= :until), 0) AS balance
            FROM account a WHERE a.user_id = :user
            SQL, ['user' => $user->getId(), 'until' => $until->format('Y-m-d')]);

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['id']] = round((float) $row['balance'], 2);
        }

        return $out;
    }

    public function balance(Account $account): float
    {
        return $this->balances($account->getUser())[$account->getId()] ?? 0.0;
    }

    /** Patrimonio liquido: somma dei conti non archiviati. */
    public function total(User $user, array $accounts): float
    {
        $balances = $this->balances($user);
        $sum = 0.0;
        foreach ($accounts as $account) {
            if (!$account->isArchived()) {
                $sum += $balances[$account->getId()] ?? 0.0;
            }
        }

        return round($sum, 2);
    }
}
