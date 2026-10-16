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

    /** @return list<Account> */
    public function findForUser(User $user, bool $withArchived = false): array
    {
        $qb = $this->createQueryBuilder('a')
            ->andWhere('a.user = :user')->setParameter('user', $user)
            ->orderBy('a.archived', \SortDirection::Ascending)->addOrderBy('a.isDefault', \SortDirection::Descending)
            ->addOrderBy('a.position', \SortDirection::Ascending)->addOrderBy('a.name', \SortDirection::Ascending);
        if (!$withArchived) {
            $qb->andWhere('a.archived = false');
        }

        return $qb->getQuery()->getResult();
    }

    public function findDefault(User $user): ?Account
    {
        return $this->findForUser($user)[0] ?? null;
    }
}
