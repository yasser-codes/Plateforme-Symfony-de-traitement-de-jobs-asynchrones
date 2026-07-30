<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\CreateJobRequest;
use App\Dto\ListJobsQuery;
use App\Entity\Job;
use App\Entity\JobLog;
use App\Enum\JobStatus;
use App\Message\PriorityJobMessage;
use App\Message\ProcessJobMessage;
use App\Repository\JobLogRepository;
use App\Repository\JobRepository;
use App\Service\ApiCache;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Uid\Uuid;

#[Route(
    path: '/api',
    name: 'api_',
    defaults: ['_format' => 'json'],
)]
final class JobController extends AbstractController
{
    public function __construct(
        private readonly JobRepository $jobRepository,
        private readonly JobLogRepository $jobLogRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ApiCache $apiCache,
        private readonly NormalizerInterface $normalizer,
        private readonly MessageBusInterface $messageBus,

        #[Autowire(service: 'monolog.logger.api')]
        private LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: '/jobs',
        name: 'jobs_create',
        methods: ['POST'],
    )]
    public function create(
        #[MapRequestPayload]
        CreateJobRequest $request,
    ): JsonResponse {
        $job = new Job(
            type: $request->type,
            payload: $request->payload,
            priority: $request->priority,
        );

        $log = new JobLog(
            level: 'INFO',
            message: 'Job créé et enregistré.',
            context: [
                'status' => JobStatus::PENDING->value,
                'priority' => $request->priority,
            ],
        );

        $job->addLog($log);

        $this->entityManager->persist($job);
        $this->entityManager->flush();

        $jobId = $job->getId()->toRfc4122();

        if ($job->getPriority() > 0) {
            $this->messageBus->dispatch(
                new PriorityJobMessage($jobId),
            );
        } else {
            $this->messageBus->dispatch(
                new ProcessJobMessage($jobId),
            );
        }

        $job->setStatus(JobStatus::QUEUED);

        $this->entityManager->flush();
        $this->apiCache->clear();

        $this->logger->info(
            'Job soumis via l’API.',
            [
                'job_id' => $job->getId()->toRfc4122(),
                'type' => $job->getType(),
                'priority' => $job->getPriority(),
                'status' => $job->getStatus()->value,
            ],
        );

        return $this->json(
            data: $job,
            status: Response::HTTP_CREATED,
            context: [
                'groups' => ['job:detail'],
            ],
        );
    }

    #[Route(
        path: '/jobs',
        name: 'jobs_list',
        methods: ['GET'],
    )]
    public function list(
        #[MapQueryString]
        ListJobsQuery $query,
    ): JsonResponse {
        $status = null !== $query->status
            ? JobStatus::from($query->status)
            : null;

        $cacheKey = sprintf(
            'api.jobs.list.page_%d.limit_%d.status_%s.type_%s.priority_%s',
            $query->page,
            $query->limit,
            $query->status ?? 'all',
            $query->type ?? 'all',
            null !== $query->priority
                ? (string) $query->priority
                : 'all',
        );

        $data = $this->apiCache->get(
            $cacheKey,
            function () use ($query, $status): array {
                $result = $this->jobRepository->findPaginated(
                    page: $query->page,
                    limit: $query->limit,
                    status: $status,
                    type: $query->type,
                    priority: $query->priority,
                );

                $totalPages = 0 === $result['total']
                    ? 0
                    : (int) ceil(
                        $result['total'] / $query->limit,
                    );

                return [
                    'items' => $this->normalizer->normalize(
                        $result['items'],
                        null,
                        [
                            'groups' => ['job:list'],
                        ],
                    ),
                    'pagination' => [
                        'page' => $query->page,
                        'limit' => $query->limit,
                        'total_items' => $result['total'],
                        'total_pages' => $totalPages,
                    ],
                    'filters' => [
                        'status' => $query->status,
                        'type' => $query->type,
                        'priority' => $query->priority,
                    ],
                ];
            },
        );

        return $this->json(
            data: $data,
            headers: [
                'X-Cache-TTL' => (string) $this->apiCache->getTtl(),
            ],
        );
    }

    #[Route(
        path: '/jobs/{id}',
        name: 'jobs_show',
        requirements: [
            'id' => '[0-9a-fA-F-]{36}',
        ],
        methods: ['GET'],
    )]
    public function show(string $id): JsonResponse
    {
        $job = $this->findJobOrFail($id);

        $data = $this->apiCache->get(
            'api.jobs.detail.'.$id,
            fn (): mixed => $this->normalizer->normalize(
                $job,
                null,
                [
                    'groups' => ['job:detail'],
                ],
            ),
        );

        return $this->json(
            data: $data,
            headers: [
                'X-Cache-TTL' => (string) $this->apiCache->getTtl(),
            ],
        );
    }

    #[Route(
        path: '/jobs/{id}/logs',
        name: 'jobs_logs',
        requirements: [
            'id' => '[0-9a-fA-F-]{36}',
        ],
        methods: ['GET'],
    )]
    public function logs(string $id): JsonResponse
    {
        $job = $this->findJobOrFail($id);

        $data = $this->apiCache->get(
            'api.jobs.logs.'.$id,
            function () use ($job): array {
                $logs = $this->jobLogRepository
                    ->findByJobOrdered($job);

                return [
                    'job_id' => $job->getId()->toRfc4122(),
                    'items' => $this->normalizer->normalize(
                        $logs,
                        null,
                        [
                            'groups' => ['job_log:list'],
                        ],
                    ),
                    'total' => count($logs),
                ];
            },
        );

        return $this->json(
            data: $data,
            headers: [
                'X-Cache-TTL' => (string) $this->apiCache->getTtl(),
            ],
        );
    }

    #[Route(
        path: '/jobs/{id}/retry',
        name: 'jobs_retry',
        requirements: [
            'id' => '[0-9a-fA-F-]{36}',
        ],
        methods: ['POST'],
    )]
    public function retry(string $id): JsonResponse
    {
        $job = $this->findJobOrFail($id);

        if (JobStatus::FAILED !== $job->getStatus()) {
            return $this->json(
                data: [
                    'error' => 'JOB_NOT_RETRYABLE',
                    'message' => 'Seul un job en statut FAILED peut être relancé.',
                    'current_status' => $job->getStatus()->value,
                ],
                status: Response::HTTP_CONFLICT,
            );
        }

        $job
            ->setStatus(JobStatus::QUEUED)
            ->setErrorMessage(null)
            ->setStartedAt(null)
            ->setCompletedAt(null)
            ->setProcessedBy(null);

        $job->addLog(
            new JobLog(
                level: 'WARNING',
                message: 'Relance manuelle demandée.',
                context: [
                    'retry_count_before_retry' => $job->getRetryCount(),
                ],
            ),
        );

        $jobId = $job->getId()->toRfc4122();

        if ($job->getPriority() > 0) {
            $this->messageBus->dispatch(
                new PriorityJobMessage($jobId),
            );
        } else {
            $this->messageBus->dispatch(
                new ProcessJobMessage($jobId),
            );
        }

        $this->entityManager->flush();
        $this->apiCache->clear();

        $this->logger->warning(
            'Relance manuelle d’un job depuis l’API.',
            [
                'job_id' => $job->getId()->toRfc4122(),
                'type' => $job->getType(),
                'priority' => $job->getPriority(),
                'retry_count' => $job->getRetryCount(),
            ],
        );

        return $this->json(
            data: $job,
            context: [
                'groups' => ['job:detail'],
            ],
        );
    }

    #[Route(
        path: '/jobs/{id}',
        name: 'jobs_delete',
        requirements: [
            'id' => '[0-9a-fA-F-]{36}',
        ],
        methods: ['DELETE'],
    )]
    public function delete(string $id): JsonResponse
    {
        $job = $this->findJobOrFail($id);

        $allowedStatuses = [
            JobStatus::PENDING,
            JobStatus::QUEUED,
        ];

        if (!in_array($job->getStatus(), $allowedStatuses, true)) {
            return $this->json(
                data: [
                    'error' => 'JOB_NOT_CANCELLABLE',
                    'message' => 'Seul un job PENDING ou QUEUED peut être supprimé.',
                    'current_status' => $job->getStatus()->value,
                ],
                status: Response::HTTP_CONFLICT,
            );
        }

        $deletedJobContext = [
            'job_id' => $job->getId()->toRfc4122(),
            'type' => $job->getType(),
            'priority' => $job->getPriority(),
            'status' => $job->getStatus()->value,
        ];

        $this->entityManager->remove($job);
        $this->entityManager->flush();

        $this->apiCache->clear();

        $this->logger->warning(
            'Job supprimé via l’API.',
            $deletedJobContext,
        );

        return new JsonResponse(
            data: null,
            status: Response::HTTP_NO_CONTENT,
        );
    }

    #[Route(
        path: '/stats',
        name: 'stats',
        methods: ['GET'],
    )]
    public function stats(): JsonResponse
    {
        $data = $this->apiCache->get(
            'api.stats',
            function (): array {
                return [
                    'total_jobs' => $this->jobRepository->count([]),
                    'by_status' => $this->jobRepository->countByStatus(),
                    'by_type' => $this->jobRepository->countByType(),
                    'generated_at' => (
                        new \DateTimeImmutable()
                    )->format(DATE_ATOM),
                ];
            },
        );

        return $this->json(
            data: $data,
            headers: [
                'X-Cache-TTL' => (string) $this->apiCache->getTtl(),
            ],
        );
    }

    private function findJobOrFail(string $id): Job
    {
        if (!Uuid::isValid($id)) {
            throw $this->createNotFoundException('L’identifiant fourni n’est pas un UUID valide.');
        }

        $job = $this->jobRepository->find(
            Uuid::fromString($id),
        );

        if (!$job instanceof Job) {
            throw $this->createNotFoundException('Le job demandé est introuvable.');
        }

        return $job;
    }
}
