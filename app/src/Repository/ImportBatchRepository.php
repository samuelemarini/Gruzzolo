<?php

namespace App\Repository;

use App\Entity\ImportBatch;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ImportBatch> */
class ImportBatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImportBatch::class);
    }

    /** @return list<ImportBatch> */
    public function findForUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['createdAt' => \SortDirection::Descending]);
    }
}
