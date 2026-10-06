<?php

declare(strict_types=1);

namespace Dniccum\Linear\Data;

use Dniccum\Linear\Support\Json;

final readonly class Comment
{
    public function __construct(
        public string $id,
        public ?string $url,
    ) {}

    /**
     * @param  array<string, mixed>  $node
     */
    public static function fromArray(array $node): self
    {
        return new self(Json::string($node['id'] ?? null), Json::nullableString($node['url'] ?? null));
    }
}
