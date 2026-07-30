<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Job;
use App\Entity\JobLog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(JobLog::class)]
final class JobLogTest extends TestCase
{
    public function testLogIsCreatedWithExpectedValues(): void
    {
        $beforeCreation = new \DateTimeImmutable();

        $log = new JobLog(
            level: 'info',
            message: 'Le job a été créé.',
            context: [
                'source' => 'unit-test',
            ],
        );

        $afterCreation = new \DateTimeImmutable();

        self::assertInstanceOf(Uuid::class, $log->getId());
        self::assertSame('INFO', $log->getLevel());
        self::assertSame(
            'Le job a été créé.',
            $log->getMessage(),
        );
        self::assertSame(
            ['source' => 'unit-test'],
            $log->getContext(),
        );

        self::assertGreaterThanOrEqual(
            $beforeCreation,
            $log->getCreatedAt(),
        );

        self::assertLessThanOrEqual(
            $afterCreation,
            $log->getCreatedAt(),
        );
    }

    public function testLevelIsConvertedToUppercase(): void
    {
        $log = new JobLog(
            level: 'warning',
            message: 'Attention.',
        );

        self::assertSame('WARNING', $log->getLevel());

        $log->setLevel('critical');

        self::assertSame('CRITICAL', $log->getLevel());
    }

    public function testPropertiesCanBeModified(): void
    {
        $log = new JobLog(
            level: 'info',
            message: 'Ancien message.',
        );

        $log
            ->setMessage('Nouveau message.')
            ->setContext([
                'retry_count' => 2,
            ]);

        self::assertSame(
            'Nouveau message.',
            $log->getMessage(),
        );

        self::assertSame(
            ['retry_count' => 2],
            $log->getContext(),
        );
    }

    public function testJobCanBeAssigned(): void
    {
        $job = new Job(
            type: 'report',
            payload: [],
        );

        $log = new JobLog(
            level: 'info',
            message: 'Test relation.',
        );

        $log->setJob($job);

        self::assertSame($job, $log->getJob());
    }

    public function testDefaultContextIsEmpty(): void
    {
        $log = new JobLog(
            level: 'info',
            message: 'Test contexte.',
        );

        self::assertSame([], $log->getContext());
    }
}
