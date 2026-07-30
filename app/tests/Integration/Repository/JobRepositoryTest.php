<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Job;
use App\Enum\JobStatus;
use App\Repository\JobRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class JobRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private JobRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = static::getContainer();

        $this->entityManager = $container->get(
            EntityManagerInterface::class,
        );

        $this->repository = $container->get(
            JobRepository::class,
        );

        $this->clearDatabase();
    }

    protected function tearDown(): void
    {
        $this->clearDatabase();
        $this->entityManager->close();

        parent::tearDown();
    }

    public function testJobCanBePersistedAndRetrieved(): void
    {
        $job = new Job(
            type: 'report',
            payload: [
                'title' => 'Rapport annuel',
            ],
            priority: 5,
        );

        $this->entityManager->persist($job);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $storedJob = $this->repository->find($job->getId());

        self::assertInstanceOf(Job::class, $storedJob);
        self::assertSame('report', $storedJob->getType());
        self::assertSame(
            ['title' => 'Rapport annuel'],
            $storedJob->getPayload(),
        );
        self::assertSame(
            JobStatus::PENDING,
            $storedJob->getStatus(),
        );
        self::assertSame(5, $storedJob->getPriority());
    }

    public function testCountByStatusReturnsEveryStatus(): void
    {
        $pendingJob = new Job(
            type: 'report',
            payload: [],
        );

        $completedJob = new Job(
            type: 'email',
            payload: [],
        );

        $completedJob->setStatus(JobStatus::COMPLETED);

        $this->entityManager->persist($pendingJob);
        $this->entityManager->persist($completedJob);
        $this->entityManager->flush();

        $counts = $this->repository->countByStatus();

        self::assertSame(1, $counts['PENDING']);
        self::assertSame(1, $counts['COMPLETED']);
        self::assertSame(0, $counts['FAILED']);
        self::assertCount(
            count(JobStatus::cases()),
            $counts,
        );
    }

    public function testCountByTypeGroupsJobsByType(): void
    {
        $this->persistJob('report');
        $this->persistJob('report');
        $this->persistJob('email');

        $this->entityManager->flush();

        $counts = $this->repository->countByType();

        self::assertSame(2, $counts['report']);
        self::assertSame(1, $counts['email']);
    }

    public function testFindPaginatedSupportsFilters(): void
    {
        $this->persistJob(
            type: 'report',
            priority: 5,
            status: JobStatus::PENDING,
        );

        $this->persistJob(
            type: 'report',
            priority: 8,
            status: JobStatus::COMPLETED,
        );

        $this->persistJob(
            type: 'email',
            priority: 5,
            status: JobStatus::PENDING,
        );

        $this->entityManager->flush();

        $result = $this->repository->findPaginated(
            page: 1,
            limit: 10,
            status: JobStatus::PENDING,
            type: 'report',
            priority: 5,
        );

        self::assertSame(1, $result['total']);
        self::assertCount(1, $result['items']);

        $job = $result['items'][0];

        self::assertSame('report', $job->getType());
        self::assertSame(5, $job->getPriority());
        self::assertSame(
            JobStatus::PENDING,
            $job->getStatus(),
        );
    }

    public function testGetMetricsSummaryReturnsExpectedValues(): void
    {
        $completedJob = $this->persistJob(
            type: 'report',
            status: JobStatus::COMPLETED,
        );

        $completedJob
            ->setStartedAt(
                new \DateTimeImmutable('2026-07-29 10:00:00'),
            )
            ->setCompletedAt(
                new \DateTimeImmutable('2026-07-29 10:00:02'),
            )
            ->setProcessedBy('worker-1')
            ->incrementRetryCount();

        $failedJob = $this->persistJob(
            type: 'email',
            status: JobStatus::FAILED,
        );

        $failedJob
            ->setStartedAt(
                new \DateTimeImmutable('2026-07-29 10:00:00'),
            )
            ->setCompletedAt(
                new \DateTimeImmutable('2026-07-29 10:00:04'),
            )
            ->setProcessedBy('worker-2')
            ->incrementRetryCount()
            ->incrementRetryCount();

        $pendingJob = $this->persistJob(
            type: 'image',
            status: JobStatus::PENDING,
        );

        $pendingJob->setProcessedBy('worker-1');

        $this->entityManager->flush();

        $metrics = $this->repository->getMetricsSummary();

        self::assertSame(3, $metrics['total_jobs']);
        self::assertSame(1, $metrics['completed_jobs']);
        self::assertSame(1, $metrics['failed_jobs']);
        self::assertSame(3, $metrics['total_retries']);

        self::assertSame(
            3000.0,
            $metrics['average_processing_time_ms'],
        );

        self::assertSame(
            50.0,
            $metrics['success_rate_percent'],
        );

        self::assertSame(
            [
                'worker-1' => 2,
                'worker-2' => 1,
            ],
            $metrics['by_worker'],
        );
    }

    private function persistJob(
        string $type,
        int $priority = 0,
        JobStatus $status = JobStatus::PENDING,
    ): Job {
        $job = new Job(
            type: $type,
            payload: [],
            priority: $priority,
        );

        $job->setStatus($status);

        $this->entityManager->persist($job);

        return $job;
    }

    private function clearDatabase(): void
    {
        $connection = $this->entityManager->getConnection();

        $connection->executeStatement(
            'TRUNCATE TABLE job_logs, jobs CASCADE',
        );

        $this->entityManager->clear();
    }
}
