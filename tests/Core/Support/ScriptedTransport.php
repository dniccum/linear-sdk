<?php

declare(strict_types=1);

namespace Dniccum\Linear\Tests\Core\Support;

use Dniccum\Linear\Transport\Response;
use Dniccum\Linear\Transport\Transport;
use RuntimeException;
use Throwable;

/**
 * A transport that answers from a script and remembers every request. No
 * framework involved.
 */
final class ScriptedTransport implements Transport
{
    /**
     * @var list<array{url: string, headers: array<string, string>, body: array<array-key, mixed>, form: bool}>
     */
    public array $requests = [];

    /**
     * @param  list<Response|Throwable>  $script  Consumed in order; the last entry repeats.
     */
    public function __construct(
        private array $script = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function data(array $data, int $status = 200): Response
    {
        return new Response($status, ['data' => $data]);
    }

    public function postJson(string $url, array $headers, array $payload): Response
    {
        return $this->answer($url, $headers, $payload, false);
    }

    public function postForm(string $url, array $headers, array $fields): Response
    {
        return $this->answer($url, $headers, $fields, true);
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<array-key, mixed>  $body
     */
    private function answer(string $url, array $headers, array $body, bool $form): Response
    {
        $this->requests[] = ['url' => $url, 'headers' => $headers, 'body' => $body, 'form' => $form];

        $next = count($this->script) > 1 ? array_shift($this->script) : ($this->script[0] ?? throw new RuntimeException('Nothing scripted.'));

        return $next instanceof Throwable ? throw $next : $next;
    }
}
