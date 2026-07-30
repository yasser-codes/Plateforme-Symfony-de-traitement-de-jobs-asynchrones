<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;

final class WorkerHeartbeatSubscriber
{
    private const HEARTBEAT_TTL_SECONDS = 15;

    public function __construct(
        #[Autowire(env: 'REDIS_URL')]
        private readonly string $redisUrl,
    ) {
    }

    #[AsEventListener(event: WorkerStartedEvent::class)]
    public function onWorkerStarted(WorkerStartedEvent $event): void
    {
        $this->refreshHeartbeat();
    }

    #[AsEventListener(event: WorkerRunningEvent::class)]
    public function onWorkerRunning(WorkerRunningEvent $event): void
    {
        $this->refreshHeartbeat();
    }

    #[AsEventListener(event: WorkerStoppedEvent::class)]
    public function onWorkerStopped(WorkerStoppedEvent $event): void
    {
        $workerName = $this->getWorkerName();

        if (null === $workerName) {
            return;
        }

        try {
            $redis = $this->createRedisConnection();
            $redis->del($this->getHeartbeatKey($workerName));
            $redis->close();
        } catch (\Throwable) {
            // Le worker ne doit pas s’arrêter à cause du monitoring.
        }
    }

    private function refreshHeartbeat(): void
    {
        $workerName = $this->getWorkerName();

        if (null === $workerName) {
            return;
        }

        try {
            $redis = $this->createRedisConnection();

            $redis->setEx(
                $this->getHeartbeatKey($workerName),
                self::HEARTBEAT_TTL_SECONDS,
                (new \DateTimeImmutable())->format(DATE_ATOM),
            );

            $redis->close();
        } catch (\Throwable) {
            // Une panne du monitoring ne doit pas interrompre le worker.
        }
    }

    private function getWorkerName(): ?string
    {
        $workerName = $_SERVER['WORKER_NAME']
            ?? $_ENV['WORKER_NAME']
            ?? getenv('WORKER_NAME');

        if (!is_string($workerName) || '' === $workerName) {
            return null;
        }

        return $workerName;
    }

    private function getHeartbeatKey(string $workerName): string
    {
        return 'worker:heartbeat:'.$workerName;
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
