<?php

namespace App\Repository;

use App\Entity\Account;
use App\Entity\Category;
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
    public function findTree(Account $account, ?string $kind = null, bool $withArchived = false): array
    {
        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.children', 'ch')->addSelect('ch')
            ->andWhere('c.account = :account')->setParameter('account', $account)
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
    public function findFlat(Account $account, ?string $kind = null): array
    {
        $flat = [];
        foreach ($this->findTree($account, $kind) as $parent) {
            $flat[] = $parent;
            foreach ($parent->getChildren() as $child) {
                if (!$child->isArchived()) {
                    $flat[] = $child;
                }
            }
        }

        return $flat;
    }

    /**
     * Copia tutte le categorie (con sottocategorie, icone, colori e ordine) da un conto a un altro.
     *
     * @return int quante categorie sono state create
     */
    public function copyAll(Account $from, Account $to): int
    {
        $em = $this->getEntityManager();
        $created = 0;
        // Quelle che il conto ha gia' (stesso nome e tipo) non si duplicano: si completano le sottocategorie.
        $existing = [];
        foreach ($to->getId() ? $this->findTree($to, null, true) : [] as $c) {
            $existing[$c->getKind().'|'.mb_strtolower($c->getName())] = $c;
        }
        foreach ($this->findTree($from) as $parent) {
            $target = $existing[$parent->getKind().'|'.mb_strtolower($parent->getName())] ?? null;
            if (!$target) {
                $target = $this->copyOne($parent, $to, null);
                $em->persist($target);
                ++$created;
            }
            $children = array_map(fn (Category $c) => mb_strtolower($c->getName()), $target->getChildren()->toArray());
            foreach ($parent->getChildren() as $child) {
                if (!$child->isArchived() && !in_array(mb_strtolower($child->getName()), $children, true)) {
                    $em->persist($this->copyOne($child, $to, $target));
                    ++$created;
                }
            }
        }

        return $created;
    }

    /** Categoria del conto con questo nome e tipo (senza badare a maiuscole), principale o sottocategoria. */
    public function findOneByName(Account $account, string $kind, string $name): ?Category
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.account = :account')->setParameter('account', $account)
            ->andWhere('c.kind = :kind')->setParameter('kind', $kind)
            ->andWhere('LOWER(c.name) = :name')->setParameter('name', mb_strtolower(trim($name)))
            ->addSelect('CASE WHEN c.parent IS NULL THEN 0 ELSE 1 END AS HIDDEN isChild')
            ->orderBy('isChild', \SortDirection::Ascending)
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    private function copyOne(Category $c, Account $to, ?Category $parent): Category
    {
        return (new Category($to))->setParent($parent)->setName($c->getName())->setKind($c->getKind())
            ->setIcon($c->getIcon())->setColor($c->getColor())->setFuel($c->isFuel())->setPosition($c->getPosition());
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

    public function nextPosition(Account $account, ?Category $parent): int
    {
        $qb = $this->createQueryBuilder('c')->select('MAX(c.position)')
            ->andWhere('c.account = :account')->setParameter('account', $account);
        $parent ? $qb->andWhere('c.parent = :p')->setParameter('p', $parent) : $qb->andWhere('c.parent IS NULL');

        return (int) $qb->getQuery()->getSingleScalarResult() + 1;
    }
}
