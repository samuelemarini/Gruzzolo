<?php

namespace App\Repository;

use App\Entity\Holding;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Holding> */
class HoldingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Holding::class);
    }

    /** @return list<Holding> */
    public function findForUser(User $user, bool $withArchived = false): array
    {
        $criteria = ['user' => $user];
        if (!$withArchived) {
            $criteria['archived'] = false;
        }

        return $this->findBy($criteria, ['archived' => \SortDirection::Ascending, 'name' => \SortDirection::Ascending]);
    }
}
