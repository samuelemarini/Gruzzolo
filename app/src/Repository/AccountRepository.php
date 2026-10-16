<?php

namespace App\Repository;

use App\Entity\Account;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Account> */
class AccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Account::class);
    }

    /**
     * Conti propri e conti condivisi con l'utente; prima i propri, cosi' il predefinito e' sempre suo.
     *
     * @return list<Account>
     */
    public function findForUser(User $user, bool $withArchived = false): array
    {
        $qb = $this->createQueryBuilder('a')
            ->leftJoin('a.members', 'mem')
            ->andWhere('a.user = :user OR mem = :user')->setParameter('user', $user)
            ->addSelect('CASE WHEN a.user = :user THEN 0 ELSE 1 END AS HIDDEN notMine')
            ->orderBy('a.archived', \SortDirection::Ascending)->addOrderBy('notMine', \SortDirection::Ascending)
            ->addOrderBy('a.isDefault', \SortDirection::Descending)
            ->addOrderBy('a.position', \SortDirection::Ascending)->addOrderBy('a.name', \SortDirection::Ascending)
            ->distinct();
        if (!$withArchived) {
            $qb->andWhere('a.archived = false');
        }

        return $qb->getQuery()->getResult();
    }

    public function findDefault(User $user): ?Account
    {
        $first = $this->findForUser($user)[0] ?? null;

        return $first?->isOwnedBy($user) ? $first : null;
    }

    /** DQL dei conti visibili all'utente (propri + condivisi), da usare come sottoquery con :user. */
    public const ACCESSIBLE_DQL = 'SELECT acc_a.id FROM App\\Entity\\Account acc_a LEFT JOIN acc_a.members acc_m WHERE acc_a.user = :user OR acc_m = :user';

    /** Stessa cosa in SQL, per le query DBAL (parametro :user = id utente). */
    public const ACCESSIBLE_SQL = 'SELECT a2.id FROM account a2 LEFT JOIN account_member am2 ON am2.account_id = a2.id WHERE a2.user_id = :user OR am2.user_id = :user';
}
