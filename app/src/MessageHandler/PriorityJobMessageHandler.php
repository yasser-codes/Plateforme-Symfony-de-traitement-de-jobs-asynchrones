<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\PriorityJobMessage;
use App\Repository\JobRepository;
use App\Service\JobProcessor;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(fromTransport: 'async_priority')]
final readonly class PriorityJobMessageHandler
{
    public function __construct(
        private JobRepository $jobRepository,
        private JobProcessor $jobProcessor,
    ) {
    }

    public function __invoke(PriorityJobMessage $message): void
    {
        if (!Uuid::isValid($message->jobId)) {
            throw new \InvalidArgumentException(sprintf('L’identifiant "%s" n’est pas un UUID valide.', $message->jobId));
        }

        $job = $this->jobRepository->find(
            Uuid::fromString($message->jobId),
        );

        if (null === $job) {
            throw new \RuntimeException(sprintf('Le job prioritaire "%s" est introuvable.', $message->jobId));
        }

        $this->jobProcessor->process(
            job: $job,
            workerName: 'worker-2',
        );
    }
}
