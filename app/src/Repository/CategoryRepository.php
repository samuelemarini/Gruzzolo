<?php

namespace App\Repository;

use App\Entity\Category;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Category> */
class CategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    /**
     * Categorie principali con le loro sottocategorie, nell'ordine scelto dall'utente.
     *
     * @return list<Category>
     */
    public function findTree(User $user, ?string $kind = null, bool $withArchived = false): array
    {
        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.children', 'ch')->addSelect('ch')
            ->andWhere('c.user = :user')->setParameter('user', $user)
            ->andWhere('c.parent IS NULL')
            ->orderBy('c.position', \SortDirection::Ascending)->addOrderBy('c.name', \SortDirection::Ascending);
        if ($kind !== null) {
            $qb->andWhere('c.kind = :kind')->setParameter('kind', $kind);
        }
        if (!$withArchived) {
            $qb->andWhere('c.archived = false');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Elenco piatto per i selettori: ogni principale seguita dalle sue sottocategorie attive.
     *
     * @return list<Category>
     */
    public function findFlat(User $user, ?string $kind = null): array
    {
        $flat = [];
        foreach ($this->findTree($user, $kind) as $parent) {
            $flat[] = $parent;
            foreach ($parent->getChildren() as $child) {
                if (!$child->isArchived()) {
                    $flat[] = $child;
                }
            }
        }

        return $flat;
    }

    /** @return list<int> la categoria e tutte le sue sottocategorie */
    public function idsWithChildren(Category $category): array
    {
        $ids = [$category->getId()];
        foreach ($category->getChildren() as $child) {
            $ids[] = $child->getId();
        }

        return $ids;
    }

    public function nextPosition(User $user, ?Category $parent): int
    {
        $qb = $this->createQueryBuilder('c')->select('MAX(c.position)')
            ->andWhere('c.user = :user')->setParameter('user', $user);
        $parent ? $qb->andWhere('c.parent = :p')->setParameter('p', $parent) : $qb->andWhere('c.parent IS NULL');

        return (int) $qb->getQuery()->getSingleScalarResult() + 1;
    }
}
