<?php

namespace App\Repository;

use App\Entity\Tag;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Tag> */
class TagRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tag::class);
    }

    /** @return list<Tag> */
    public function findForUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['name' => \SortDirection::Ascending]);
    }

    public function findOneByName(User $user, string $name): ?Tag
    {
        return $this->findOneBy(['user' => $user, 'name' => Tag::normalize($name)]);
    }
}
