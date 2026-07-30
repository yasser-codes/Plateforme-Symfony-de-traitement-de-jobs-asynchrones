<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Job;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class JobControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        $this->entityManager = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $this->clearDatabase();
    }

    protected function tearDown(): void
    {
        $this->clearDatabase();
        $this->entityManager->close();

        parent::tearDown();
    }

    public function testCreateJobWithValidPayload(): void
    {
        $this->client->jsonRequest(
            method: 'POST',
            uri: '/api/jobs',
            parameters: [
                'type' => 'report',
                'payload' => [
                    'title' => 'Rapport fonctionnel',
                ],
                'priority' => 0,
            ],
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_CREATED,
        );

        self::assertResponseFormatSame('json');

        /** @var array<string, mixed> $response */
        $response = json_decode(
            $this->client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame('report', $response['type']);
        self::assertSame(
            ['title' => 'Rapport fonctionnel'],
            $response['payload'],
        );
        self::assertSame('QUEUED', $response['status']);
        self::assertSame(0, $response['priority']);

        $repository = $this->entityManager->getRepository(
            Job::class,
        );

        self::assertSame(1, $repository->count([]));
    }

    public function testCreateJobRejectsInvalidType(): void
    {
        $this->client->jsonRequest(
            method: 'POST',
            uri: '/api/jobs',
            parameters: [
                'type' => 'unknown_type',
                'payload' => [],
                'priority' => 0,
            ],
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );

        self::assertResponseFormatSame('json');

        $repository = $this->entityManager->getRepository(
            Job::class,
        );

        self::assertSame(0, $repository->count([]));
    }

    public function testListJobsReturnsPaginationAndItems(): void
    {
        $job = new Job(
            type: 'report',
            payload: ['title' => 'Rapport test'],
            priority: 4,
        );

        $this->entityManager->persist($job);
        $this->entityManager->flush();

        $this->client->request(
            method: 'GET',
            uri: '/api/jobs?page=1&limit=10&type=report',
        );

        self::assertResponseIsSuccessful();
        self::assertResponseFormatSame('json');

        /** @var array<string, mixed> $response */
        $response = json_decode(
            $this->client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertArrayHasKey('items', $response);
        self::assertArrayHasKey('pagination', $response);
        self::assertArrayHasKey('filters', $response);

        self::assertCount(1, $response['items']);
        self::assertSame(
            1,
            $response['pagination']['total_items'],
        );
        self::assertSame(
            'report',
            $response['filters']['type'],
        );
    }

    public function testShowJobReturnsRequestedJob(): void
    {
        $job = new Job(
            type: 'email_campaign',
            payload: [
                'campaign' => 'summer',
            ],
            priority: 2,
        );

        $this->entityManager->persist($job);
        $this->entityManager->flush();

        $id = $job->getId()->toRfc4122();

        $this->client->request(
            method: 'GET',
            uri: '/api/jobs/'.$id,
        );

        self::assertResponseIsSuccessful();
        self::assertResponseFormatSame('json');

        /** @var array<string, mixed> $response */
        $response = json_decode(
            $this->client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame($id, $response['id']);
        self::assertSame(
            'email_campaign',
            $response['type'],
        );
        self::assertSame(
            ['campaign' => 'summer'],
            $response['payload'],
        );
    }

    public function testShowJobReturnsNotFoundForUnknownUuid(): void
    {
        $this->client->request(
            method: 'GET',
            uri: '/api/jobs/00000000-0000-0000-0000-000000000000',
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_NOT_FOUND,
        );

        self::assertResponseFormatSame('json');
    }

    public function testJobLogsReturnsAssociatedLogs(): void
    {
        $job = new Job(
            type: 'report',
            payload: [],
        );

        $job->addLog(
            new \App\Entity\JobLog(
                level: 'info',
                message: 'Premier log.',
                context: ['step' => 1],
            ),
        );

        $job->addLog(
            new \App\Entity\JobLog(
                level: 'warning',
                message: 'Deuxième log.',
                context: ['step' => 2],
            ),
        );

        $this->entityManager->persist($job);
        $this->entityManager->flush();

        $this->client->request(
            method: 'GET',
            uri: '/api/jobs/'.$job->getId()->toRfc4122().'/logs',
        );

        self::assertResponseIsSuccessful();
        self::assertResponseFormatSame('json');

        /** @var array<string, mixed> $response */
        $response = json_decode(
            $this->client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(
            $job->getId()->toRfc4122(),
            $response['job_id'],
        );

        self::assertSame(2, $response['total']);
        self::assertCount(2, $response['items']);

        self::assertSame(
            'INFO',
            $response['items'][0]['level'],
        );

        self::assertSame(
            'WARNING',
            $response['items'][1]['level'],
        );
    }

    public function testFailedJobCanBeRetried(): void
    {
        $job = new Job(
            type: 'report',
            payload: [],
            priority: 0,
        );

        $job
            ->setStatus(\App\Enum\JobStatus::FAILED)
            ->setErrorMessage('Erreur temporaire.')
            ->setStartedAt(
                new \DateTimeImmutable('2026-07-29 10:00:00'),
            )
            ->setCompletedAt(
                new \DateTimeImmutable('2026-07-29 10:00:02'),
            )
            ->setProcessedBy('worker-1')
            ->incrementRetryCount();

        $this->entityManager->persist($job);
        $this->entityManager->flush();

        $id = $job->getId()->toRfc4122();

        $this->client->request(
            method: 'POST',
            uri: '/api/jobs/'.$id.'/retry',
        );

        self::assertResponseIsSuccessful();
        self::assertResponseFormatSame('json');

        /** @var array<string, mixed> $response */
        $response = json_decode(
            $this->client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame('QUEUED', $response['status']);
        self::assertNull($response['errorMessage']);
        self::assertNull($response['startedAt']);
        self::assertNull($response['completedAt']);
        self::assertNull($response['processedBy']);
        self::assertSame(1, $response['retryCount']);

        $this->entityManager->clear();

        $storedJob = $this->entityManager
            ->getRepository(Job::class)
            ->find($job->getId());

        self::assertInstanceOf(Job::class, $storedJob);
        self::assertSame(
            \App\Enum\JobStatus::QUEUED,
            $storedJob->getStatus(),
        );
    }

    public function testPendingJobCannotBeRetried(): void
    {
        $job = new Job(
            type: 'report',
            payload: [],
        );

        $this->entityManager->persist($job);
        $this->entityManager->flush();

        $this->client->request(
            method: 'POST',
            uri: '/api/jobs/'.$job->getId()->toRfc4122().'/retry',
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_CONFLICT,
        );

        self::assertResponseFormatSame('json');

        /** @var array<string, mixed> $response */
        $response = json_decode(
            $this->client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(
            'JOB_NOT_RETRYABLE',
            $response['error'],
        );

        self::assertSame(
            'PENDING',
            $response['current_status'],
        );
    }

    public function testQueuedJobCanBeDeleted(): void
    {
        $job = new Job(
            type: 'email_campaign',
            payload: [],
        );

        $job->setStatus(
            \App\Enum\JobStatus::QUEUED,
        );

        $this->entityManager->persist($job);
        $this->entityManager->flush();

        $id = $job->getId();

        $this->client->request(
            method: 'DELETE',
            uri: '/api/jobs/'.$id->toRfc4122(),
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_NO_CONTENT,
        );

        $this->entityManager->clear();

        self::assertNull(
            $this->entityManager
                ->getRepository(Job::class)
                ->find($id),
        );
    }

    public function testCompletedJobCannotBeDeleted(): void
    {
        $job = new Job(
            type: 'image_processing',
            payload: [],
        );

        $job->setStatus(
            \App\Enum\JobStatus::COMPLETED,
        );

        $this->entityManager->persist($job);
        $this->entityManager->flush();

        $this->client->request(
            method: 'DELETE',
            uri: '/api/jobs/'.$job->getId()->toRfc4122(),
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_CONFLICT,
        );

        self::assertResponseFormatSame('json');

        /** @var array<string, mixed> $response */
        $response = json_decode(
            $this->client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(
            'JOB_NOT_CANCELLABLE',
            $response['error'],
        );

        self::assertSame(
            'COMPLETED',
            $response['current_status'],
        );
    }

    private function clearDatabase(): void
    {
        $connection = $this->entityManager->getConnection();

        $connection->executeStatement(
            'TRUNCATE TABLE job_logs, jobs CASCADE',
        );

        $connection->executeStatement(
            'TRUNCATE TABLE messenger_messages RESTART IDENTITY',
        );

        $this->entityManager->clear();
    }
}
