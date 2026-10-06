<?php

declare(strict_types=1);

namespace Dniccum\Linear\Data;

use Dniccum\Linear\Support\Json;

final readonly class Team extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $key,
    ) {}

    /**
     * @param  array<string, mixed>  $node
     */
    public static function fromArray(array $node): self
    {
        return new self(Json::string($node['id'] ?? null), Json::string($node['name'] ?? null), Json::string($node['key'] ?? null));
    }

    /**
     * @return array{id: string, name: string, key: string}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'key' => $this->key];
    }
}
