<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\JobStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JobStatus::class)]
final class JobStatusTest extends TestCase
{
    public function testEnumContainsExpectedCases(): void
    {
        self::assertSame(
            [
                JobStatus::PENDING,
                JobStatus::QUEUED,
                JobStatus::PROCESSING,
                JobStatus::COMPLETED,
                JobStatus::FAILED,
                JobStatus::RETRYING,
            ],
            JobStatus::cases(),
        );
    }

    public function testValuesReturnsExpectedStrings(): void
    {
        self::assertSame(
            [
                'PENDING',
                'QUEUED',
                'PROCESSING',
                'COMPLETED',
                'FAILED',
                'RETRYING',
            ],
            JobStatus::values(),
        );
    }

    public function testEnumCanBeCreatedFromValidString(): void
    {
        self::assertSame(
            JobStatus::COMPLETED,
            JobStatus::from('COMPLETED'),
        );
    }

    public function testEnumRejectsInvalidString(): void
    {
        $this->expectException(\ValueError::class);

        JobStatus::from('UNKNOWN');
    }
}
