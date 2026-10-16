<?php

namespace App\Repository;

use App\Entity\Refuel;
use App\Entity\Vehicle;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Refuel> */
class RefuelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Refuel::class);
    }

    /** @return list<Refuel> in ordine di chilometraggio */
    public function findForVehicle(Vehicle $vehicle): array
    {
        return $this->createQueryBuilder('r')
            ->join('r.movement', 'm')->addSelect('m')
            ->andWhere('m.vehicle = :v')->setParameter('v', $vehicle)
            ->orderBy('r.odometer', \SortDirection::Ascending)->addOrderBy('m.date', \SortDirection::Ascending)
            ->getQuery()->getResult();
    }

    /** Ultimo rifornimento registrato prima di una certa data (escluso il movimento in modifica). */
    public function findPrevious(Vehicle $vehicle, ?int $excludeMovementId = null): ?Refuel
    {
        $qb = $this->createQueryBuilder('r')
            ->join('r.movement', 'm')
            ->andWhere('m.vehicle = :v')->setParameter('v', $vehicle)
            ->orderBy('r.odometer', \SortDirection::Descending)
            ->setMaxResults(1);
        if ($excludeMovementId) {
            $qb->andWhere('m.id <> :ex')->setParameter('ex', $excludeMovementId);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }
}
