<?php

declare(strict_types=1);

namespace App\Message;

final readonly class DeadLetterMessage
{
    public function __construct(
        public string $jobId,
        public string $originalMessageClass,
        public string $errorMessage,
    ) {
    }
}
