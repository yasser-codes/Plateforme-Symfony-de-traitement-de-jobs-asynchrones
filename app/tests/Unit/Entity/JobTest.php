<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Job;
use App\Entity\JobLog;
use App\Enum\JobStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(Job::class)]
final class JobTest extends TestCase
{
    public function testJobIsCreatedWithExpectedInitialValues(): void
    {
        $job = new Job(
            type: 'report',
            payload: [
                'title' => 'Rapport annuel',
            ],
            priority: 3,
        );

        self::assertInstanceOf(Uuid::class, $job->getId());
        self::assertSame('report', $job->getType());
        self::assertSame(
            ['title' => 'Rapport annuel'],
            $job->getPayload(),
        );
        self::assertSame(JobStatus::PENDING, $job->getStatus());
        self::assertSame(3, $job->getPriority());
        self::assertSame(0, $job->getRetryCount());
        self::assertNull($job->getStartedAt());
        self::assertNull($job->getCompletedAt());
        self::assertNull($job->getErrorMessage());
        self::assertNull($job->getProcessedBy());
        self::assertCount(0, $job->getLogs());
    }

    public function testJobPropertiesCanBeModified(): void
    {
        $job = new Job(
            type: 'report',
            payload: [],
        );

        $startedAt = new \DateTimeImmutable(
            '2026-07-29 10:00:00',
        );

        $completedAt = new \DateTimeImmutable(
            '2026-07-29 10:00:05',
        );

        $job
            ->setType('image_processing')
            ->setPayload(['file' => 'image.png'])
            ->setStatus(JobStatus::COMPLETED)
            ->setStartedAt($startedAt)
            ->setCompletedAt($completedAt)
            ->setErrorMessage(null)
            ->setProcessedBy('worker-2')
            ->setPriority(8);

        self::assertSame(
            'image_processing',
            $job->getType(),
        );

        self::assertSame(
            ['file' => 'image.png'],
            $job->getPayload(),
        );

        self::assertSame(
            JobStatus::COMPLETED,
            $job->getStatus(),
        );

        self::assertSame(
            $startedAt,
            $job->getStartedAt(),
        );

        self::assertSame(
            $completedAt,
            $job->getCompletedAt(),
        );

        self::assertNull($job->getErrorMessage());
        self::assertSame(
            'worker-2',
            $job->getProcessedBy(),
        );
        self::assertSame(8, $job->getPriority());
    }

    public function testRetryCountCanBeIncrementedAndReset(): void
    {
        $job = new Job(
            type: 'report',
            payload: [],
        );

        $job->incrementRetryCount();
        $job->incrementRetryCount();

        self::assertSame(2, $job->getRetryCount());

        $job->resetRetryCount();

        self::assertSame(0, $job->getRetryCount());
    }

    public function testMinimumPriorityIsAccepted(): void
    {
        $job = new Job(
            type: 'report',
            payload: [],
            priority: -10,
        );

        self::assertSame(-10, $job->getPriority());
    }

    public function testMaximumPriorityIsAccepted(): void
    {
        $job = new Job(
            type: 'report',
            payload: [],
            priority: 10,
        );

        self::assertSame(10, $job->getPriority());
    }

    public function testPriorityLowerThanMinimumIsRejected(): void
    {
        $this->expectException(
            \InvalidArgumentException::class,
        );

        new Job(
            type: 'report',
            payload: [],
            priority: -11,
        );
    }

    public function testPriorityHigherThanMaximumIsRejected(): void
    {
        $this->expectException(
            \InvalidArgumentException::class,
        );

        new Job(
            type: 'report',
            payload: [],
            priority: 11,
        );
    }

    public function testLogCanBeAddedToJob(): void
    {
        $job = new Job(
            type: 'report',
            payload: [],
        );

        $log = new JobLog(
            level: 'info',
            message: 'Job créé.',
            context: [
                'source' => 'unit-test',
            ],
        );

        $job->addLog($log);

        self::assertCount(1, $job->getLogs());
        self::assertTrue($job->getLogs()->contains($log));
        self::assertSame($job, $log->getJob());
        self::assertSame('INFO', $log->getLevel());
    }

    public function testSameLogIsNotAddedTwice(): void
    {
        $job = new Job(
            type: 'report',
            payload: [],
        );

        $log = new JobLog(
            level: 'info',
            message: 'Job créé.',
        );

        $job->addLog($log);
        $job->addLog($log);

        self::assertCount(1, $job->getLogs());
    }

    public function testLogCanBeRemovedFromJob(): void
    {
        $job = new Job(
            type: 'report',
            payload: [],
        );

        $log = new JobLog(
            level: 'warning',
            message: 'Test suppression.',
        );

        $job->addLog($log);
        $job->removeLog($log);

        self::assertCount(0, $job->getLogs());
        self::assertFalse($job->getLogs()->contains($log));
    }
}
