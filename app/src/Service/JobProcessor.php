<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Job;
use App\Entity\JobLog;
use App\Enum\JobStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class JobProcessor
{
    public function __construct(
        private EntityManagerInterface $entityManager,

        #[Autowire(service: 'monolog.logger.worker')]
        private LoggerInterface $logger,

        private ApiCache $apiCache,
    ) {
    }

    public function process(Job $job, string $workerName): void
    {
        $this->logger->info(
            'Démarrage du traitement du job.',
            [
                'job_id' => $job->getId()->toRfc4122(),
                'job_type' => $job->getType(),
                'worker' => $workerName,
            ],
        );

        $job->setStatus(JobStatus::PROCESSING);
        $job->setStartedAt(new \DateTimeImmutable());
        $job->setCompletedAt(null);
        $job->setProcessedBy($workerName);
        $job->setErrorMessage(null);

        $this->addLog(
            job: $job,
            level: 'info',
            message: sprintf(
                'Traitement démarré par le worker %s.',
                $workerName,
            ),
            context: [
                'worker' => $workerName,
                'job_type' => $job->getType(),
            ],
        );

        $this->entityManager->flush();
        $this->apiCache->clear();

        try {
            $this->executeJob($job);

            $job->setStatus(JobStatus::COMPLETED);
            $job->setCompletedAt(new \DateTimeImmutable());

            $this->addLog(
                job: $job,
                level: 'info',
                message: 'Job traité avec succès.',
                context: [
                    'worker' => $workerName,
                ],
            );

            $this->logger->info(
                'Job traité avec succès.',
                [
                    'job_id' => $job->getId()->toRfc4122(),
                    'worker' => $workerName,
                ],
            );

            $this->entityManager->flush();
            $this->apiCache->clear();
        } catch (\Throwable $exception) {
            $job->setStatus(JobStatus::FAILED);
            $job->setCompletedAt(new \DateTimeImmutable());
            $job->setErrorMessage($exception->getMessage());
            $job->incrementRetryCount();

            $this->addLog(
                job: $job,
                level: 'error',
                message: $exception->getMessage(),
                context: [
                    'worker' => $workerName,
                    'retry_count' => $job->getRetryCount(),
                    'exception_class' => $exception::class,
                ],
            );

            $this->logger->error(
                'Échec du traitement du job.',
                [
                    'job_id' => $job->getId()->toRfc4122(),
                    'worker' => $workerName,
                    'retry_count' => $job->getRetryCount(),
                    'exception' => $exception,
                ],
            );

            $this->entityManager->flush();
            $this->apiCache->clear();

            throw $exception;
        }
    }

    private function executeJob(Job $job): void
    {
        sleep(3);

        $payload = $job->getPayload();

        if (($payload['force_failure'] ?? false) === true) {
            throw new \RuntimeException('Échec volontaire demandé dans le payload.');
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function addLog(
        Job $job,
        string $level,
        string $message,
        array $context = [],
    ): void {
        $log = new JobLog(
            level: $level,
            message: $message,
            context: $context,
        );

        $job->addLog($log);

        $this->entityManager->persist($log);
    }
}
