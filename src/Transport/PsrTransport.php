<?php

declare(strict_types=1);

namespace Dniccum\Linear\Transport;

use Override;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Sends requests to Linear through any PSR-18 client, building them with PSR-17
 * factories.
 *
 * Timeouts, retries at the HTTP level and proxies belong to the PSR-18 client
 * you pass in. Linear answers within a few seconds, so configure a timeout of
 * about 15 seconds.
 */
final readonly class PsrTransport implements Transport
{
    public function __construct(
        private ClientInterface $client,
        private RequestFactoryInterface $requests,
        private StreamFactoryInterface $streams,
    ) {}

    /**
     * POST a JSON document.
     *
     * @param  array<string, string>  $headers
     * @param  array<array-key, mixed>  $payload
     *
     * @throws ClientExceptionInterface When the request could not be sent.
     */
    #[Override]
    public function postJson(string $url, array $headers, array $payload): Response
    {
        return $this->send($url, $headers + ['Accept' => 'application/json'], 'application/json', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * POST a URL-encoded form.
     *
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $fields
     *
     * @throws ClientExceptionInterface When the request could not be sent.
     */
    #[Override]
    public function postForm(string $url, array $headers, array $fields): Response
    {
        return $this->send($url, $headers + ['Accept' => 'application/json'], 'application/x-www-form-urlencoded', http_build_query($fields));
    }

    /**
     * @param  array<string, string>  $headers
     *
     * @throws ClientExceptionInterface
     */
    private function send(string $url, array $headers, string $contentType, string $body): Response
    {
        $request = $this->requests->createRequest('POST', $url)
            ->withHeader('Content-Type', $contentType)
            ->withBody($this->streams->createStream($body));

        return Response::fromPsr($this->client->sendRequest($this->withHeaders($request, $headers)));
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function withHeaders(RequestInterface $request, array $headers): RequestInterface
    {
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }
}
