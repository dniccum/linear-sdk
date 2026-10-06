<?php

declare(strict_types=1);

namespace Dniccum\Linear\Data\Settings;

use Dniccum\Linear\Data\Data;
use Dniccum\Linear\Enums\LinearSendMode;
use Dniccum\Linear\Models\LinearDestination;

/**
 * A saved destination in the camelCase shape of the HTTP contract. A missing
 * priority is Linear's 0, "no priority".
 */
final readonly class DestinationData extends Data
{
    /**
     * @param  list<string>  $labelIds
     */
    public function __construct(
        public LinearSendMode $sendMode,
        public string $teamId,
        public ?string $teamName,
        public ?string $projectId,
        public ?string $stateId,
        public array $labelIds,
        public int $priority,
        public ?string $assigneeId,
    ) {}

    public static function fromModel(LinearDestination $destination): self
    {
        return new self(
            sendMode: $destination->send_mode,
            teamId: $destination->team_id,
            teamName: $destination->team_name,
            projectId: $destination->project_id,
            stateId: $destination->state_id,
            labelIds: array_values($destination->label_ids ?? []),
            priority: $destination->priority ?? 0,
            assigneeId: $destination->assignee_id,
        );
    }

    /**
     * @return array{sendMode: string, teamId: string, teamName: ?string, projectId: ?string, stateId: ?string, labelIds: list<string>, priority: int, assigneeId: ?string}
     */
    public function toArray(): array
    {
        return [
            'sendMode' => $this->sendMode->value,
            'teamId' => $this->teamId,
            'teamName' => $this->teamName,
            'projectId' => $this->projectId,
            'stateId' => $this->stateId,
            'labelIds' => $this->labelIds,
            'priority' => $this->priority,
            'assigneeId' => $this->assigneeId,
        ];
    }
}
