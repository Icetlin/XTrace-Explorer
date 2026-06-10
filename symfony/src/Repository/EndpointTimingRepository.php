<?php

namespace App\Repository;

use App\Entity\EndpointTiming;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class EndpointTimingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EndpointTiming::class);
    }

    /** @return EndpointTiming[] */
    public function findByTraceName(string $traceName, int $limit = 100): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.traceName = :name')
            ->setParameter('name', $traceName)
            ->orderBy('t.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
