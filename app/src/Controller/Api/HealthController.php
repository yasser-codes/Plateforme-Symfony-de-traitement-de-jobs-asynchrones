<?php

declare(strict_types=1);

namespace App\Controller\Api;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class HealthController
{
    public function __construct(
        private Connection $connection,
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
        path: '/health',
        name: 'api_health',
        methods: ['GET'],
        format: 'json',
    )]
    public function __invoke(): JsonResponse
    {
        $database = $this->checkDatabase();
        $redis = $this->checkRedis();
        $rabbitMq = $this->checkRabbitMq();
        $workers = $this->checkWorkers();

        $healthy = 'up' === $database['status']
            && 'up' === $redis['status']
            && 'up' === $rabbitMq['status']
            && 'up' === $workers['status'];

        return new JsonResponse(
            data: [
                'status' => $healthy ? 'healthy' : 'unhealthy',
                'checks' => [
                    'database' => $database,
                    'redis' => $redis,
                    'rabbitmq' => $rabbitMq,
                    'workers' => $workers,
                ],
                'timestamp' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ],
            status: $healthy
                ? Response::HTTP_OK
                : Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function checkDatabase(): array
    {
        $start = microtime(true);

        try {
            $result = $this->connection
                ->executeQuery('SELECT 1')
                ->fetchOne();

            if (1 !== $result && '1' !== $result) {
                throw new \RuntimeException('La base de données a retourné une réponse inattendue.');
            }

            return [
                'status' => 'up',
                'response_time_ms' => $this->elapsedMilliseconds($start),
            ];
        } catch (\Throwable $exception) {
            return [
                'status' => 'down',
                'response_time_ms' => $this->elapsedMilliseconds($start),
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function checkRedis(): array
    {
        $start = microtime(true);

        try {
            $redis = $this->createRedisConnection();

            $pong = $redis->ping();
            $redis->close();

            if (true !== $pong && '+PONG' !== $pong && 'PONG' !== $pong) {
                throw new \RuntimeException('Redis n’a pas retourné PONG.');
            }

            return [
                'status' => 'up',
                'response_time_ms' => $this->elapsedMilliseconds($start),
            ];
        } catch (\Throwable $exception) {
            return [
                'status' => 'down',
                'response_time_ms' => $this->elapsedMilliseconds($start),
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function checkRabbitMq(): array
    {
        $start = microtime(true);

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

            $consumers = 0;
            $messagesReady = 0;
            $messagesUnacknowledged = 0;

            foreach ($queues as $queue) {
                if (!is_array($queue)) {
                    continue;
                }

                $consumersValue = $queue['consumers'] ?? 0;
                $messagesReadyValue = $queue['messages_ready'] ?? 0;
                $messagesUnacknowledgedValue =
                    $queue['messages_unacknowledged'] ?? 0;

                $consumers += is_int($consumersValue)
                    ? $consumersValue
                    : 0;

                $messagesReady += is_int($messagesReadyValue)
                    ? $messagesReadyValue
                    : 0;

                $messagesUnacknowledged +=
                    is_int($messagesUnacknowledgedValue)
                        ? $messagesUnacknowledgedValue
                        : 0;
            }

            return [
                'status' => 'up',
                'response_time_ms' => $this->elapsedMilliseconds($start),
                'queues' => count($queues),
                'consumers' => $consumers,
                'messages_ready' => $messagesReady,
                'messages_unacknowledged' => $messagesUnacknowledged,
            ];
        } catch (\Throwable $exception) {
            return [
                'status' => 'down',
                'response_time_ms' => $this->elapsedMilliseconds($start),
                'queues' => 0,
                'consumers' => 0,
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function checkWorkers(): array
    {
        $start = microtime(true);

        $expectedWorkers = [
            'worker-1',
            'worker-2',
            'worker-dead-letter',
        ];

        try {
            $redis = $this->createRedisConnection();

            $workers = [];
            $activeWorkers = 0;

            foreach ($expectedWorkers as $workerName) {
                $key = 'worker:heartbeat:'.$workerName;

                $heartbeat = $redis->get($key);
                $ttl = $redis->ttl($key);

                $active = is_string($heartbeat) && $ttl > 0;

                if ($active) {
                    ++$activeWorkers;
                }

                $workers[$workerName] = [
                    'status' => $active ? 'up' : 'down',
                    'last_heartbeat' => false !== $heartbeat
                        ? $heartbeat
                        : null,
                    'ttl_seconds' => $ttl > 0 ? $ttl : 0,
                ];
            }

            $redis->close();

            $allWorkersActive = $activeWorkers === count($expectedWorkers);

            return [
                'status' => $allWorkersActive ? 'up' : 'down',
                'active' => $activeWorkers,
                'expected' => count($expectedWorkers),
                'response_time_ms' => $this->elapsedMilliseconds($start),
                'items' => $workers,
            ];
        } catch (\Throwable $exception) {
            return [
                'status' => 'down',
                'active' => 0,
                'expected' => count($expectedWorkers),
                'response_time_ms' => $this->elapsedMilliseconds($start),
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

    private function elapsedMilliseconds(float $start): float
    {
        return round(
            (microtime(true) - $start) * 1000,
            2,
        );
    }
}
