<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Repository\JobRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class MetricsController
{
    public function __construct(
        private JobRepository $jobRepository,
        private HttpClientInterface $httpClient,

        #[Autowire(env: 'REDIS_URL')]
        private string $redisUrl,

        #[Autowire(env: 'RABBITMQ_DEFAULT_USER')]
        private string $rabbitMqUser,

        #[Autowire(env: 'RABBITMQ_DEFAULT_PASS')]
        private string $rabbitMqPassword,
    ) {
    }

    #[Route(
        path: '/metrics',
        name: 'api_metrics',
        methods: ['GET'],
        format: 'json',
    )]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse([
            'jobs' => [
                'summary' => $this->jobRepository->getMetricsSummary(),
                'by_status' => $this->jobRepository->countByStatus(),
                'by_type' => $this->jobRepository->countByType(),
            ],
            'rabbitmq' => $this->getRabbitMqMetrics(),
            'workers' => $this->getWorkerMetrics(),
            'generated_at' => (
                new \DateTimeImmutable()
            )->format(DATE_ATOM),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function getRabbitMqMetrics(): array
    {
        try {
            $response = $this->httpClient->request(
                method: 'GET',
                url: 'http://rabbitmq:15672/api/queues/jobs',
                options: [
                    'auth_basic' => [
                        $this->rabbitMqUser,
                        $this->rabbitMqPassword,
                    ],
                    'timeout' => 3,
                ],
            );

            $queues = $response->toArray();

            $items = [];
            $totalReady = 0;
            $totalUnacknowledged = 0;

            foreach ($queues as $queue) {
                $name = (string) ($queue['name'] ?? 'unknown');

                $messagesReady = (int) (
                    $queue['messages_ready'] ?? 0
                );

                $messagesUnacknowledged = (int) (
                    $queue['messages_unacknowledged'] ?? 0
                );

                $totalReady += $messagesReady;
                $totalUnacknowledged += $messagesUnacknowledged;

                $items[$name] = [
                    'messages_ready' => $messagesReady,
                    'messages_unacknowledged' => $messagesUnacknowledged,
                    'consumers' => (int) (
                        $queue['consumers'] ?? 0
                    ),
                ];
            }

            return [
                'status' => 'available',
                'queue_count' => count($queues),
                'messages_ready' => $totalReady,
                'messages_unacknowledged' => $totalUnacknowledged,
                'queues' => $items,
            ];
        } catch (\Throwable $exception) {
            return [
                'status' => 'unavailable',
                'queue_count' => 0,
                'messages_ready' => 0,
                'messages_unacknowledged' => 0,
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getWorkerMetrics(): array
    {
        $expectedWorkers = [
            'worker-1',
            'worker-2',
            'worker-dead-letter',
        ];

        try {
            $redis = $this->createRedisConnection();

            $items = [];
            $activeWorkers = 0;

            foreach ($expectedWorkers as $workerName) {
                $key = 'worker:heartbeat:'.$workerName;

                $heartbeat = $redis->get($key);
                $ttl = $redis->ttl($key);

                $active = is_string($heartbeat) && $ttl > 0;

                if ($active) {
                    ++$activeWorkers;
                }

                $items[$workerName] = [
                    'active' => $active,
                    'last_heartbeat' => false !== $heartbeat
                        ? $heartbeat
                        : null,
                    'ttl_seconds' => $ttl > 0 ? $ttl : 0,
                ];
            }

            $redis->close();

            return [
                'active' => $activeWorkers,
                'expected' => count($expectedWorkers),
                'items' => $items,
            ];
        } catch (\Throwable $exception) {
            return [
                'active' => 0,
                'expected' => count($expectedWorkers),
                'error' => $exception->getMessage(),
            ];
        }
    }

    private function createRedisConnection(): \Redis
    {
        $parts = parse_url($this->redisUrl);

        if (false === $parts || !isset($parts['host'])) {
            throw new \RuntimeException('REDIS_URL invalide.');
        }

        $redis = new \Redis();

        $connected = $redis->connect(
            host: $parts['host'],
            port: $parts['port'] ?? 6379,
            timeout: 2.0,
        );

        if (!$connected) {
            throw new \RuntimeException('Impossible de se connecter à Redis.');
        }

        if (isset($parts['pass']) && '' !== $parts['pass']) {
            $redis->auth($parts['pass']);
        }

        return $redis;
    }
}
