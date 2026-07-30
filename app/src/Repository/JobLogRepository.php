<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Job;
use App\Entity\JobLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<JobLog>
 */
final class JobLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JobLog::class);
    }

    /**
     * @return list<JobLog>
     */
    public function findByJobOrdered(Job $job): array
    {
        /** @var list<JobLog> $logs */
        $logs = $this->createQueryBuilder('log')
            ->andWhere('log.job = :job')
            ->setParameter('job', $job)
            ->orderBy('log.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $logs;
    }
}
