<?php

declare(strict_types=1);

namespace Dniccum\Linear\Support;

use Closure;
use Dniccum\Linear\Contracts\Mutex;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;

/**
 * A {@see Mutex} on the cache locks of `illuminate/cache`. Throws
 * `LockTimeoutException` when the lock cannot be taken in time. A store without
 * lock support runs the callback unlocked.
 *
 * Outside Laravel, a file store shares the lock between processes:
 *
 *     new CacheMutex(new Repository(new FileStore(new Filesystem, '/var/cache/linear')))
 */
final readonly class CacheMutex implements Mutex
{
    public function __construct(
        private Repository $cache,
    ) {}

    public function synchronized(string $key, int $ttlSeconds, int $waitSeconds, Closure $callback): mixed
    {
        $store = $this->cache->getStore();

        return $store instanceof LockProvider
            ? $store->lock($key, $ttlSeconds)->block($waitSeconds, $callback)
            : $callback();
    }
}
