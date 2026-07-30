<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\FailedJob;
use App\Entity\JobLog;
use App\Enum\JobStatus;
use App\Message\DeadLetterMessage;
use App\Repository\JobRepository;
use App\Service\ApiCache;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(fromTransport: 'async_dead_letter')]
final readonly class DeadLetterMessageHandler
{
    public function __construct(
        private JobRepository $jobRepository,
        private EntityManagerInterface $entityManager,

        #[Autowire(service: 'monolog.logger.messenger')]
        private LoggerInterface $logger,

        private ApiCache $apiCache,
    ) {
    }

    public function __invoke(DeadLetterMessage $message): void
    {
        if (!Uuid::isValid($message->jobId)) {
            $this->logger->critical(
                'DeadLetterMessage contenant un UUID invalide.',
                [
                    'job_id' => $message->jobId,
                ],
            );

            return;
        }

        $job = $this->jobRepository->find(
            Uuid::fromString($message->jobId),
        );

        if (null === $job) {
            $this->logger->critical(
                'Job introuvable pendant le traitement Dead Letter.',
                [
                    'job_id' => $message->jobId,
                ],
            );

            return;
        }

        $job
            ->setStatus(JobStatus::FAILED)
            ->setErrorMessage($message->errorMessage)
            ->setCompletedAt(
                $job->getCompletedAt() ?? new \DateTimeImmutable(),
            );

        $failedJob = new FailedJob();
        $failedJob->setJobId($job->getId());
        $failedJob->setMessageClass(
            $message->originalMessageClass,
        );
        $failedJob->setErrorMessage(
            $message->errorMessage,
        );
        $failedJob->setRetryCount(
            $job->getRetryCount(),
        );

        $jobLog = new JobLog(
            level: 'CRITICAL',
            message: sprintf(
                'Job enregistré dans la Dead Letter Queue après %d échec(s).',
                $job->getRetryCount(),
            ),
            context: [
                'job_id' => $message->jobId,
                'message_class' => $message->originalMessageClass,
                'retry_count' => $job->getRetryCount(),
                'error_message' => $message->errorMessage,
            ],
        );

        $job->addLog($jobLog);

        $this->entityManager->persist($failedJob);
        $this->entityManager->persist($jobLog);
        $this->entityManager->flush();

        $this->apiCache->clear();

        $this->logger->critical(
            'Job définitivement échoué et enregistré dans failed_job.',
            [
                'job_id' => $message->jobId,
                'retry_count' => $job->getRetryCount(),
            ],
        );
    }
}
