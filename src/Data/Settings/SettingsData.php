<?php

declare(strict_types=1);

namespace Dniccum\Linear\Data\Settings;

use Dniccum\Linear\Data\Data;
use Dniccum\Linear\Enums\LinearAuthMode;

/**
 * Everything the configuration page needs, serialized to the `Settings`
 * payload of docs/http-contract.md (camelCase keys).
 */
final readonly class SettingsData extends Data
{
    /**
     * @param  list<FailureData>  $failures
     */
    public function __construct(
        public bool $configured,
        public LinearAuthMode $authMode,
        public BrandData $brand,
        public string $csrf,
        public UrlsData $urls,
        public ?BackData $back,
        public ?ConnectionData $connection,
        public ?DestinationData $destination,
        public array $failures,
        public FlashData $flash,
    ) {}

    /**
     * JSON that is safe inside a `<script type="application/json">` element:
     * no sequence in it can close the tag or open a comment.
     */
    public function toScriptJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array{configured: bool, authMode: string, brand: array<string, mixed>, csrf: string, urls: array<string, string>, back: array{label: string, url: string}|null, connection: array<string, mixed>|null, destination: array<string, mixed>|null, failures: list<array<string, mixed>>, flash: array<string, string|null>}
     */
    public function toArray(): array
    {
        return [
            'configured' => $this->configured,
            'authMode' => $this->authMode->value,
            'brand' => $this->brand->toArray(),
            'csrf' => $this->csrf,
            'urls' => $this->urls->toArray(),
            'back' => $this->back?->toArray(),
            'connection' => $this->connection?->toArray(),
            'destination' => $this->destination?->toArray(),
            'failures' => array_map(fn (FailureData $failure): array => $failure->toArray(), $this->failures),
            'flash' => $this->flash->toArray(),
        ];
    }
}
