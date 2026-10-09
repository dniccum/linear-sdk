<?php

declare(strict_types=1);

namespace Dniccum\Linear\Contracts;

use Closure;

/**
 * A named lock, so only one worker at a time refreshes a connection's OAuth
 * token (Linear rotates the refresh token on every use).
 *
 * Back it with whatever your framework offers: Laravel's cache locks (done for
 * you), the Symfony Lock component, a database advisory lock. The default
 * does no locking, which is fine for a single worker.
 */
interface Mutex
{
    /**
     * Run the callback while holding the lock named $key, waiting up to
     * $waitSeconds to get it and releasing it after at most $ttlSeconds.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function synchronized(string $key, int $ttlSeconds, int $waitSeconds, Closure $callback): mixed;
}
