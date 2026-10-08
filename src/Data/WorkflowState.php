<?php

declare(strict_types=1);

namespace Dniccum\Linear\Data;

use Dniccum\Linear\Enums\LinearStateType;
use Dniccum\Linear\Support\Json;

final readonly class WorkflowState extends Data
{
    /**
     * @param  string  $type  triage, backlog, unstarted, started, completed, canceled or duplicate.
     * @param  ?string  $color  Hex colour such as "#5e6ad2".
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $type,
        public ?string $color = null,
    ) {}

    /**
     * @param  array<string, mixed>  $node
     */
    public static function fromArray(array $node): self
    {
        return new self(
            Json::string($node['id'] ?? null),
            Json::string($node['name'] ?? null),
            Json::string($node['type'] ?? null),
            Json::nullableString($node['color'] ?? null),
        );
    }

    /**
     * The state's type as an enum, or null for a type this package does not
     * know (Linear may add one).
     */
    public function kind(): ?LinearStateType
    {
        return LinearStateType::tryFrom($this->type);
    }

    /**
     * @return array{id: string, name: string, type: string, color: ?string}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'type' => $this->type, 'color' => $this->color];
    }
}
