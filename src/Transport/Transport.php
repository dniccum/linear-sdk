<?php

declare(strict_types=1);

namespace Dniccum\Linear\Transport;

use Psr\Http\Client\ClientExceptionInterface;

/**
 * How the core talks HTTP to Linear. {@see PsrTransport} does it with any PSR-18
 * client; tests can substitute their own.
 */
interface Transport
{
    /**
     * POST a JSON document.
     *
     * @param  array<string, string>  $headers
     * @param  array<array-key, mixed>  $payload
     *
     * @throws ClientExceptionInterface When the request could not be sent.
     */
    public function postJson(string $url, array $headers, array $payload): Response;

    /**
     * POST a URL-encoded form.
     *
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $fields
     *
     * @throws ClientExceptionInterface When the request could not be sent.
     */
    public function postForm(string $url, array $headers, array $fields): Response;
}
