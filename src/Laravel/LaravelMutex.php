<?php

declare(strict_types=1);

namespace Dniccum\Linear\Laravel;

use Closure;
use Dniccum\Linear\Contracts\Mutex;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\LockProvider;

/**
 * A {@see Mutex} on Laravel's cache locks. Throws Laravel's
 * `LockTimeoutException` when the lock cannot be taken in time. A cache store
 * without lock support runs the callback unlocked.
 */
final readonly class LaravelMutex implements Mutex
{
    public function __construct(
        private Factory $cache,
    ) {}

    public function synchronized(string $key, int $ttlSeconds, int $waitSeconds, Closure $callback): mixed
    {
        $store = $this->cache->store()->getStore();

        return $store instanceof LockProvider
            ? $store->lock($key, $ttlSeconds)->block($waitSeconds, $callback)
            : $callback();
    }
}
