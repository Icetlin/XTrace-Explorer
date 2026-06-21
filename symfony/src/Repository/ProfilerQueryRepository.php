<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProfilerQuery;
use App\Entity\ProfilerSnapshot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ProfilerQueryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProfilerQuery::class);
    }

    /** @return ProfilerQuery[] */
    public function findBySnapshot(ProfilerSnapshot $s): array
    {
        return $this->createQueryBuilder('q')
            ->where('q.snapshot = :s')
            ->setParameter('s', $s)
            ->orderBy('q.n', 'ASC')
            ->getQuery()
            ->getResult();
    }
}