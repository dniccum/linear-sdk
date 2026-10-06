<?php

declare(strict_types=1);

namespace Dniccum\Linear\Data;

use Dniccum\Linear\Support\Json;

/**
 * The Linear user who authorized the connection, and their workspace.
 */
final readonly class Viewer
{
    public function __construct(
        public string $id,
        public ?string $name,
        public ?string $email,
        public Organization $organization,
    ) {}

    /**
     * @param  array<string, mixed>  $node
     */
    public static function fromArray(array $node): self
    {
        return new self(
            id: Json::string($node['id'] ?? null),
            name: Json::nullableString($node['name'] ?? null),
            email: Json::nullableString($node['email'] ?? null),
            organization: Organization::fromArray(Json::map($node['organization'] ?? null)),
        );
    }
}
