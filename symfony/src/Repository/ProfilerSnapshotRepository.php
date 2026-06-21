<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProfilerSnapshot;
use App\Entity\TraceFile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ProfilerSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProfilerSnapshot::class);
    }

    public function findForTraceFile(TraceFile $tf): ?ProfilerSnapshot
    {
        return $this->findOneBy(['traceFile' => $tf]);
    }

    public function findForTraceFileId(int $traceFileId): ?ProfilerSnapshot
    {
        return $this->findOneBy(['traceFile' => $traceFileId]);
    }
}