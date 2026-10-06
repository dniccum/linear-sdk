<?php

declare(strict_types=1);

namespace Dniccum\Linear\Data;

use Dniccum\Linear\Support\Json;

/**
 * Where an issue is filed in Linear. Every ID belongs to the connected
 * workspace; only the team is required. Priority follows Linear's scale:
 * 0 none, 1 urgent, 2 high, 3 medium, 4 low.
 */
final readonly class Destination extends Data
{
    /**
     * @param  list<string>  $labelIds
     */
    public function __construct(
        public string $teamId,
        public ?string $projectId = null,
        public ?string $stateId = null,
        public array $labelIds = [],
        public ?int $priority = null,
        public ?string $assigneeId = null,
    ) {}

    /**
     * Build from snake_case input (validated request data, stored JSON or
     * model columns). Blank IDs mean "not set".
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            teamId: Json::string($data['team_id'] ?? null),
            projectId: Json::nullableString($data['project_id'] ?? null),
            stateId: Json::nullableString($data['state_id'] ?? null),
            labelIds: array_values(array_unique(Json::strings($data['label_ids'] ?? null))),
            priority: Json::integer($data['priority'] ?? null),
            assigneeId: Json::nullableString($data['assignee_id'] ?? null),
        );
    }

    /**
     * A copy with the given snake_case fields replaced.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function with(array $overrides): self
    {
        return self::fromArray([...$this->toArray(), ...$overrides]);
    }

    /**
     * @return array{team_id: string, project_id: ?string, state_id: ?string, label_ids: list<string>, priority: ?int, assignee_id: ?string}
     */
    public function toArray(): array
    {
        return [
            'team_id' => $this->teamId,
            'project_id' => $this->projectId,
            'state_id' => $this->stateId,
            'label_ids' => $this->labelIds,
            'priority' => $this->priority,
            'assignee_id' => $this->assigneeId,
        ];
    }
}
