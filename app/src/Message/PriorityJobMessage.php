<?php

declare(strict_types=1);

namespace App\Message;

final readonly class PriorityJobMessage implements JobMessageInterface
{
    public function __construct(
        public string $jobId,
    ) {
    }

    public function getJobId(): string
    {
        return $this->jobId;
    }
}
