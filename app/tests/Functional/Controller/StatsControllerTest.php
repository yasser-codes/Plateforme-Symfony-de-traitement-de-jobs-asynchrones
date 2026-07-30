<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Job;
use App\Enum\JobStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class StatsControllerTest extends WebTestCase
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

    public function testStatsReturnsExpectedStructure(): void
    {
        $reportJob = new Job(
            type: 'report',
            payload: [],
        );

        $emailJob = new Job(
            type: 'email_campaign',
            payload: [],
        );

        $emailJob->setStatus(
            JobStatus::COMPLETED,
        );

        $this->entityManager->persist($reportJob);
        $this->entityManager->persist($emailJob);
        $this->entityManager->flush();

        $this->client->request(
            method: 'GET',
            uri: '/api/stats',
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

        self::assertArrayHasKey(
            'total_jobs',
            $response,
        );

        self::assertArrayHasKey(
            'by_status',
            $response,
        );

        self::assertArrayHasKey(
            'by_type',
            $response,
        );

        self::assertArrayHasKey(
            'generated_at',
            $response,
        );

        self::assertSame(
            2,
            $response['total_jobs'],
        );

        self::assertSame(
            1,
            $response['by_status']['PENDING'],
        );

        self::assertSame(
            1,
            $response['by_status']['COMPLETED'],
        );

        self::assertSame(
            1,
            $response['by_type']['report'],
        );

        self::assertSame(
            1,
            $response['by_type']['email_campaign'],
        );

        self::assertNotFalse(
            \DateTimeImmutable::createFromFormat(
                DATE_ATOM,
                $response['generated_at'],
            ),
        );
    }

    public function testStatsReturnsZeroValuesWhenDatabaseIsEmpty(): void
    {
        $this->client->request(
            method: 'GET',
            uri: '/api/stats',
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
            0,
            $response['total_jobs'],
        );

        self::assertSame(
            0,
            array_sum($response['by_status']),
        );

        self::assertSame(
            [],
            $response['by_type'],
        );
    }

    private function clearDatabase(): void
    {
        $connection = $this->entityManager->getConnection();

        $connection->executeStatement(
            'TRUNCATE TABLE job_logs, jobs CASCADE',
        );

        $this->entityManager->clear();

        $cache = static::getContainer()->get(
            \App\Service\ApiCache::class,
        );

        $cache->clear();
    }
}
