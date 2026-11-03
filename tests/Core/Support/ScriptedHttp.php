<?php

declare(strict_types=1);

namespace Dniccum\Linear\Tests\Core\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Factory;
use RuntimeException;
use Throwable;

/**
 * The standalone Illuminate HTTP client (no Laravel booted), answering from a
 * script. Inspect what was sent with `$http->recorded()`.
 */
final class ScriptedHttp
{
    /**
     * @param  list<PromiseInterface|Throwable>  $script  Consumed in order; the last entry repeats.
     */
    public static function make(array $script): Factory
    {
        $http = new Factory;

        $http->fake(function () use (&$script): PromiseInterface {
            $next = count($script) > 1 ? array_shift($script) : ($script[0] ?? throw new RuntimeException('Nothing scripted.'));

            return $next instanceof Throwable ? throw $next : $next;
        });

        return $http;
    }

    /**
     * A GraphQL response carrying `data`.
     *
     * @param  array<string, mixed>  $data
     */
    public static function data(array $data, int $status = 200): PromiseInterface
    {
        return Factory::response(['data' => $data], $status);
    }
}
