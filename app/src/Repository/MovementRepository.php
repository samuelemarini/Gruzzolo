<?php

namespace App\Repository;

use App\Entity\Movement;
use App\Entity\User;
use App\Model\MovementFilter;
use App\Util\Money;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Movement> */
class MovementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Movement::class);
    }

    /** @return list<Movement> */
    public function search(User $user, MovementFilter $filter, int $limit = 60, int $offset = 0): array
    {
        return $this->filtered($user, $filter)
            ->leftJoin('m.category', 'cat')->addSelect('cat')
            ->leftJoin('cat.parent', 'catp')->addSelect('catp')
            ->leftJoin('m.tags', 'tg')->addSelect('tg')
            ->leftJoin('m.account', 'acc')->addSelect('acc')
            ->leftJoin('m.refuel', 'rf')->addSelect('rf')
            ->leftJoin('m.vehicle', 'veh')->addSelect('veh')
            ->orderBy('m.date', \SortDirection::Descending)->addOrderBy('m.id', \SortDirection::Descending)
            ->setFirstResult($offset)->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /**
     * Conteggio e totali di cio' che corrisponde ai filtri (i giroconti non sono ne' entrate ne' uscite).
     *
     * @return array{count: int, income: float, expense: float}
     */
    public function totals(User $user, MovementFilter $filter): array
    {
        $row = $this->filtered($user, $filter)
            ->select('COUNT(m.id) AS n')
            ->addSelect("COALESCE(SUM(CASE WHEN m.kind = 'income' THEN m.amount ELSE 0 END), 0) AS income")
            ->addSelect("COALESCE(SUM(CASE WHEN m.kind = 'expense' THEN m.amount ELSE 0 END), 0) AS expense")
            ->getQuery()->getSingleResult();

        return ['count' => (int) $row['n'], 'income' => (float) $row['income'], 'expense' => (float) $row['expense']];
    }

    /** @return list<Movement> */
    public function latest(User $user, int $limit): array
    {
        return $this->createQueryBuilder('m')
            ->leftJoin('m.category', 'cat')->addSelect('cat')
            ->leftJoin('m.tags', 'tg')->addSelect('tg')
            ->andWhere('m.user = :user')->setParameter('user', $user)
            ->andWhere('m.date <= :today')->setParameter('today', new \DateTimeImmutable('today'), 'date_immutable')
            ->orderBy('m.date', \SortDirection::Descending)->addOrderBy('m.id', \SortDirection::Descending)
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /** Descrizioni gia' usate, per i suggerimenti mentre si scrive. @return list<string> */
    public function recentDescriptions(User $user, int $limit = 200): array
    {
        $rows = $this->createQueryBuilder('m')
            ->select('m.description, MAX(m.date) AS HIDDEN last')
            ->andWhere('m.user = :user')->setParameter('user', $user)
            ->andWhere("m.description <> ''")
            ->groupBy('m.description')
            ->orderBy('last', \SortDirection::Descending)
            ->setMaxResults($limit)
            ->getQuery()->getSingleColumnResult();

        return array_values($rows);
    }

    private function filtered(User $user, MovementFilter $f): QueryBuilder
    {
        $qb = $this->createQueryBuilder('m')
            ->andWhere('m.user = :user')->setParameter('user', $user);

        if ($f->from) {
            $qb->andWhere('m.date >= :from')->setParameter('from', $f->from, 'date_immutable');
        }
        if ($f->to) {
            $qb->andWhere('m.date <= :to')->setParameter('to', $f->to, 'date_immutable');
        }
        if ($f->kind) {
            $qb->andWhere('m.kind = :kind')->setParameter('kind', $f->kind);
        }
        if ($f->categories) {
            $qb->andWhere('m.category IN (SELECT fc.id FROM App\Entity\Category fc WHERE fc.id IN (:cats) OR fc.parent IN (:cats))')
                ->setParameter('cats', $f->categories);
        }
        if ($f->tags) {
            $qb->andWhere('m.id IN (SELECT ft_m.id FROM App\Entity\Movement ft_m JOIN ft_m.tags ft WHERE ft.id IN (:tags))')
                ->setParameter('tags', $f->tags);
        }
        if ($f->accounts) {
            $qb->andWhere('m.account IN (:accounts) OR m.toAccount IN (:accounts)')->setParameter('accounts', $f->accounts);
        }
        if ($f->vehicle) {
            $qb->andWhere('m.vehicle = :vehicle')->setParameter('vehicle', $f->vehicle);
        }
        if ($f->min !== null) {
            $qb->andWhere('m.amount >= :min')->setParameter('min', $f->min);
        }
        if ($f->max !== null) {
            $qb->andWhere('m.amount <= :max')->setParameter('max', $f->max);
        }
        if ($f->q !== null) {
            $or = ['m.description LIKE :q', 'm.note LIKE :q',
                'm.category IN (SELECT qc.id FROM App\Entity\Category qc WHERE qc.name LIKE :q)',
                'm.id IN (SELECT qt_m.id FROM App\Entity\Movement qt_m JOIN qt_m.tags qt WHERE qt.name LIKE :q)'];
            $amount = Money::parse($f->q);
            if ($amount !== null && preg_match('/^[\d.,€\s]+$/u', $f->q)) {
                $or[] = 'm.amount = :qamount';
                $qb->setParameter('qamount', $amount);
            }
            $qb->andWhere(implode(' OR ', $or))->setParameter('q', '%'.addcslashes(ltrim($f->q, '#'), '%_').'%');
        }

        return $qb;
    }
}
