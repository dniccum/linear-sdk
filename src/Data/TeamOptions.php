<?php

declare(strict_types=1);

namespace Dniccum\Linear\Data;

/**
 * The selectable destinations inside one team. Serializes to the payload of
 * `GET teams/{team}/options`.
 */
final readonly class TeamOptions extends Data
{
    /**
     * @param  list<Project>  $projects
     * @param  list<WorkflowState>  $states
     * @param  list<Label>  $labels
     * @param  list<Member>  $members
     */
    public function __construct(
        public Team $team,
        public array $projects,
        public array $states,
        public array $labels,
        public array $members,
    ) {}

    public function hasProject(?string $id): bool
    {
        return self::contains($this->projects, $id);
    }

    public function hasState(?string $id): bool
    {
        return self::contains($this->states, $id);
    }

    public function hasMember(?string $id): bool
    {
        return self::contains($this->members, $id);
    }

    /**
     * @param  list<string>  $ids
     */
    public function hasLabels(array $ids): bool
    {
        $available = array_map(fn (Label $label): string => $label->id, $this->labels);

        return array_diff($ids, $available) === [];
    }

    /**
     * @return array{states: list<array<string, string>>, projects: list<array<string, string>>, members: list<array<string, string>>, labels: list<array<string, string|null>>}
     */
    public function toArray(): array
    {
        return [
            'states' => array_map(fn (WorkflowState $state): array => $state->toArray(), $this->states),
            'projects' => array_map(fn (Project $project): array => $project->toArray(), $this->projects),
            'members' => array_map(fn (Member $member): array => $member->toArray(), $this->members),
            'labels' => array_map(fn (Label $label): array => $label->toArray(), $this->labels),
        ];
    }

    /**
     * A null ID means "not set", which is always allowed.
     *
     * @param  list<Project|WorkflowState|Member>  $options
     */
    private static function contains(array $options, ?string $id): bool
    {
        if ($id === null) {
            return true;
        }

        foreach ($options as $option) {
            if ($option->id === $id) {
                return true;
            }
        }

        return false;
    }
}
