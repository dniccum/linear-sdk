<?php

declare(strict_types=1);

namespace Dniccum\Linear\Support;

use Closure;
use Dniccum\Linear\Contracts\Mutex;

/**
 * Takes no lock. Safe with a single worker; with several, back
 * {@see Mutex} with a real lock.
 */
final class NullMutex implements Mutex
{
    public function synchronized(string $key, int $ttlSeconds, int $waitSeconds, Closure $callback): mixed
    {
        return $callback();
    }
}
