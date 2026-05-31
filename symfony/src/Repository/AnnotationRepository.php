<?php

namespace App\Repository;

use App\Entity\Annotation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AnnotationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Annotation::class);
    }

    /** @return Annotation[] */
    public function findByTraceFile(int $traceFileId): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.traceFile = :id')
            ->setParameter('id', $traceFileId)
            ->orderBy('a.lineNo', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
