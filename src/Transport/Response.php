<?php

declare(strict_types=1);

namespace Dniccum\Linear\Transport;

use Dniccum\Linear\Support\Json;
use Psr\Http\Message\ResponseInterface;

/**
 * What came back from Linear: the status and the decoded JSON body, if it
 * had one.
 */
final readonly class Response
{
    /**
     * @param  array<array-key, mixed>|null  $data  The decoded body; null when it was not a JSON object or array.
     */
    public function __construct(
        public int $status,
        public ?array $data = null,
    ) {}

    public static function fromPsr(ResponseInterface $response): self
    {
        $decoded = json_decode((string) $response->getBody(), true);

        return new self($response->getStatusCode(), is_array($decoded) ? $decoded : null);
    }

    /**
     * A value from the decoded body (dot notation), or the whole body when
     * no key is given.
     */
    public function json(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->data : Json::get($this->data, $key, $default);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function serverError(): bool
    {
        return $this->status >= 500;
    }
}
