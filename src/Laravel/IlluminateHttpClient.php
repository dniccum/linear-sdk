<?php

declare(strict_types=1);

namespace Dniccum\Linear\Laravel;

use Dniccum\Linear\Support\Json;
use Illuminate\Http\Client\Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * A PSR-18 client on top of Laravel's HTTP client, so the SDK's requests go
 * through `Http::fake()`, `Http::preventStrayRequests()`, the configured
 * middleware and everything else your application already does with it.
 */
final readonly class IlluminateHttpClient implements ClientInterface
{
    /**
     * Headers the HTTP client sets itself from the request body.
     */
    private const array MANAGED_HEADERS = ['host', 'content-type', 'content-length'];

    public function __construct(
        private Factory $http,
        private int $timeout = 15,
    ) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $headers = [];

        foreach ($request->getHeaders() as $name => $values) {
            if (! in_array(strtolower($name), self::MANAGED_HEADERS, true)) {
                $headers[$name] = implode(', ', $values);
            }
        }

        $pending = $this->http->withHeaders($headers)->timeout($this->timeout);
        $method = $request->getMethod();
        $url = (string) $request->getUri();
        $body = (string) $request->getBody();
        $type = strtolower($request->getHeaderLine('Content-Type'));

        try {
            $response = match (true) {
                str_starts_with($type, 'application/json') && is_array($json = json_decode($body, true)) => $pending->asJson()->send($method, $url, ['json' => $json]),
                str_starts_with($type, 'application/x-www-form-urlencoded') => $pending->asForm()->send($method, $url, ['form_params' => self::parseForm($body)]),
                default => $pending->withBody($body, $type === '' ? 'application/octet-stream' : $type)->send($method, $url),
            };
        } catch (Throwable $e) {
            throw new HttpClientException($e->getMessage(), 0, $e);
        }

        return $response->toPsrResponse();
    }

    /**
     * @return array<string, mixed>
     */
    private static function parseForm(string $body): array
    {
        parse_str($body, $fields);

        return Json::map($fields);
    }
}
