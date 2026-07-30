<?php

declare(strict_types=1);

namespace App\Enum;

enum JobStatus: string
{
    case PENDING = 'PENDING';
    case QUEUED = 'QUEUED';
    case PROCESSING = 'PROCESSING';
    case COMPLETED = 'COMPLETED';
    case FAILED = 'FAILED';
    case RETRYING = 'RETRYING';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $status): string => $status->value,
            self::cases(),
        );
    }
}
