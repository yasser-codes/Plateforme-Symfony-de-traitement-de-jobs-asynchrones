<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\Job;
use App\Enum\JobStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class JobWorkflowTest extends WebTestCase
{
    public function testJobSubmissionAndStatusVerification(): void
    {
        $client = self::createClient();

        $client->request(
            method: 'POST',
            uri: '/api/jobs',
            server: [
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode(
                [
                    'type' => 'report',
                    'payload' => [
                        'format' => 'pdf',
                    ],
                    'priority' => 0,
                ],
                JSON_THROW_ON_ERROR,
            ),
        );

        self::assertResponseStatusCodeSame(201);

        $content = $client->getResponse()->getContent();

        self::assertIsString($content);

        /**
         * @var array{
         *     id: string,
         *     status: string
         * } $response
         */
        $response = json_decode(
            $content,
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertSame(
            JobStatus::QUEUED->value,
            $response['status'],
        );

        $jobId = $response['id'];

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(
            EntityManagerInterface::class,
        );

        $job = $entityManager
            ->getRepository(Job::class)
            ->find($jobId);

        self::assertInstanceOf(Job::class, $job);

        $job
            ->setStatus(JobStatus::COMPLETED)
            ->setStartedAt(new \DateTimeImmutable())
            ->setCompletedAt(new \DateTimeImmutable());

        $entityManager->flush();
        $entityManager->clear();

        $client->request(
            method: 'GET',
            uri: '/api/jobs/'.$jobId,
        );

        self::assertResponseIsSuccessful();

        $statusContent = $client
            ->getResponse()
            ->getContent();

        self::assertIsString($statusContent);

        /**
         * @var array{
         *     id: string,
         *     status: string
         * } $statusResponse
         */
        $statusResponse = json_decode(
            $statusContent,
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertSame(
            $jobId,
            $statusResponse['id'],
        );

        self::assertSame(
            JobStatus::COMPLETED->value,
            $statusResponse['status'],
        );
    }
}
