<?php

declare(strict_types=1);

namespace Dniccum\Linear\Data;

use Dniccum\Linear\Support\Json;

final readonly class Organization
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $urlKey,
    ) {}

    /**
     * @param  array<string, mixed>  $node
     */
    public static function fromArray(array $node): self
    {
        return new self(
            Json::string($node['id'] ?? null),
            Json::string($node['name'] ?? null),
            Json::nullableString($node['urlKey'] ?? null),
        );
    }
}
