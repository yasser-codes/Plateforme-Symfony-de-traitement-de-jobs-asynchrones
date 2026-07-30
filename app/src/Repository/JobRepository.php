<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Job;
use App\Enum\JobStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Job>
 */
final class JobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Job::class);
    }

    /**
     * @return array{
     *     items: list<Job>,
     *     total: int
     * }
     */
    public function findPaginated(
        int $page,
        int $limit,
        ?JobStatus $status = null,
        ?string $type = null,
        ?int $priority = null,
    ): array {
        $queryBuilder = $this->createFilteredQueryBuilder(
            $status,
            $type,
            $priority,
        );

        $queryBuilder
            ->orderBy('job.id', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        /** @var list<Job> $items */
        $items = $queryBuilder
            ->getQuery()
            ->getResult();

        $countQueryBuilder = $this->createFilteredQueryBuilder(
            $status,
            $type,
            $priority,
        );

        $total = (int) $countQueryBuilder
            ->select('COUNT(job.id)')
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'items' => $items,
            'total' => $total,
        ];
    }

    /**
     * @return array<string, int>
     */
    public function countByStatus(): array
    {
        /** @var list<array{
         *     status: JobStatus|string,
         *     total: int|string
         * }> $results
         */
        $results = $this->createQueryBuilder('job')
            ->select('job.status AS status')
            ->addSelect('COUNT(job.id) AS total')
            ->groupBy('job.status')
            ->getQuery()
            ->getArrayResult();

        $counts = [];

        foreach (JobStatus::cases() as $status) {
            $counts[$status->value] = 0;
        }

        foreach ($results as $result) {
            $status = $result['status'];

            if ($status instanceof JobStatus) {
                $status = $status->value;
            }

            $counts[(string) $status] = (int) $result['total'];
        }

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    public function countByType(): array
    {
        /** @var list<array{
         *     type: string,
         *     total: int|string
         * }> $results
         */
        $results = $this->createQueryBuilder('job')
            ->select('job.type AS type')
            ->addSelect('COUNT(job.id) AS total')
            ->groupBy('job.type')
            ->getQuery()
            ->getArrayResult();

        $counts = [];

        foreach ($results as $result) {
            $counts[(string) $result['type']] = (int) $result['total'];
        }

        return $counts;
    }

    /**
     * @return array{
     *     total_jobs: int,
     *     completed_jobs: int,
     *     failed_jobs: int,
     *     total_retries: int,
     *     average_processing_time_ms: float,
     *     success_rate_percent: float,
     *     by_worker: array<string, int>
     * }
     */
    public function getMetricsSummary(): array
    {
        $connection = $this->getEntityManager()->getConnection();

        $summarySql = <<<'SQL'
            SELECT
                COUNT(*) AS total_jobs,
                COUNT(*) FILTER (
                    WHERE status = :completed_status
                ) AS completed_jobs,
                COUNT(*) FILTER (
                    WHERE status = :failed_status
                ) AS failed_jobs,
                COALESCE(SUM(retry_count), 0) AS total_retries,
                COALESCE(
                    AVG(
                        EXTRACT(
                            EPOCH FROM (completed_at - started_at)
                        ) * 1000
                    ) FILTER (
                        WHERE started_at IS NOT NULL
                        AND completed_at IS NOT NULL
                    ),
                    0
                ) AS average_processing_time_ms
            FROM jobs
        SQL;

        /** @var array{
         *     total_jobs: int|string,
         *     completed_jobs: int|string,
         *     failed_jobs: int|string,
         *     total_retries: int|string,
         *     average_processing_time_ms: int|float|string
         * }|false $summary
         */
        $summary = $connection->fetchAssociative(
            $summarySql,
            [
                'completed_status' => JobStatus::COMPLETED->value,
                'failed_status' => JobStatus::FAILED->value,
            ],
        );

        if (false === $summary) {
            throw new \RuntimeException('Impossible de calculer les métriques des jobs.');
        }

        $totalJobs = (int) $summary['total_jobs'];
        $completedJobs = (int) $summary['completed_jobs'];
        $failedJobs = (int) $summary['failed_jobs'];

        $finishedJobs = $completedJobs + $failedJobs;

        $successRate = $finishedJobs > 0
            ? round(($completedJobs / $finishedJobs) * 100, 2)
            : 0.0;

        $workersSql = <<<'SQL'
            SELECT
                processed_by,
                COUNT(*) AS total
            FROM jobs
            WHERE processed_by IS NOT NULL
            GROUP BY processed_by
            ORDER BY processed_by
        SQL;

        /** @var list<array{
         *     processed_by: string,
         *     total: int|string
         * }> $workerResults
         */
        $workerResults = $connection->fetchAllAssociative($workersSql);

        $byWorker = [];

        foreach ($workerResults as $workerResult) {
            $workerName = (string) $workerResult['processed_by'];

            $byWorker[$workerName] = (int) $workerResult['total'];
        }

        return [
            'total_jobs' => $totalJobs,
            'completed_jobs' => $completedJobs,
            'failed_jobs' => $failedJobs,
            'total_retries' => (int) $summary['total_retries'],
            'average_processing_time_ms' => round(
                (float) $summary['average_processing_time_ms'],
                2,
            ),
            'success_rate_percent' => $successRate,
            'by_worker' => $byWorker,
        ];
    }

    private function createFilteredQueryBuilder(
        ?JobStatus $status,
        ?string $type,
        ?int $priority,
    ): QueryBuilder {
        $queryBuilder = $this->createQueryBuilder('job');

        if (null !== $status) {
            $queryBuilder
                ->andWhere('job.status = :status')
                ->setParameter('status', $status);
        }

        if (null !== $type) {
            $queryBuilder
                ->andWhere('job.type = :type')
                ->setParameter('type', $type);
        }

        if (null !== $priority) {
            $queryBuilder
                ->andWhere('job.priority = :priority')
                ->setParameter('priority', $priority);
        }

        return $queryBuilder;
    }
}
