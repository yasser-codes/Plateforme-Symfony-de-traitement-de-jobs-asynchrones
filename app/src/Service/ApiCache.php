<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final readonly class ApiCache
{
    public function __construct(
        #[Autowire(service: 'cache.api')]
        private CacheInterface $cache,

        #[Autowire(service: 'cache.api')]
        private CacheItemPoolInterface $pool,

        #[Autowire('%env(int:API_CACHE_TTL)%')]
        private int $ttl,
    ) {
    }

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function get(string $key, callable $callback): mixed
    {
        return $this->cache->get(
            $key,
            function (ItemInterface $item) use ($callback): mixed {
                $item->expiresAfter($this->ttl);

                return $callback();
            },
        );
    }

    public function clear(): void
    {
        $this->pool->clear();
    }

    public function delete(string $key): void
    {
        $this->pool->deleteItem($key);
    }

    public function getTtl(): int
    {
        return $this->ttl;
    }
}
