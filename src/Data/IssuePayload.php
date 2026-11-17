<?php

declare(strict_types=1);

namespace Dniccum\Linear\Data;

use Dniccum\Linear\Laravel\Casts\IssuePayloadCast;
use Dniccum\Linear\Support\Json;

/**
 * Everything sent to Linear to create an issue, frozen on the issue link so
 * retries re-send identical content.
 *
 * An automatic link starts with only a destination; its title and description
 * are composed on the first attempt (see hasContent()). Stored as flat
 * snake_case JSON in the issue links' payload column (Laravel casts it with
 * {@see IssuePayloadCast}).
 */
final readonly class IssuePayload extends Data
{
    public function __construct(
        public Destination $destination,
        public ?string $title = null,
        public ?string $description = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $description = $data['description'] ?? null;

        return new self(
            destination: Destination::fromArray($data),
            title: Json::nullableString($data['title'] ?? null),
            description: is_scalar($description) ? (string) $description : null,
        );
    }

    public function hasContent(): bool
    {
        return $this->title !== null && $this->description !== null;
    }

    public function withContent(string $title, string $description): self
    {
        return new self($this->destination, $title, $description);
    }

    /**
     * Linear's IssueCreateInput, using the link's client-generated ID.
     *
     * @return array<string, mixed>
     */
    public function toIssueInput(string $issueId): array
    {
        return array_filter([
            'id' => $issueId,
            'teamId' => $this->destination->teamId,
            'title' => $this->title,
            'description' => $this->description,
            'projectId' => $this->destination->projectId,
            'stateId' => $this->destination->stateId,
            'labelIds' => $this->destination->labelIds,
            'priority' => $this->destination->priority,
            'assigneeId' => $this->destination->assigneeId,
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            ...$this->destination->toArray(),
            ...array_filter(
                ['title' => $this->title, 'description' => $this->description],
                fn (?string $value): bool => $value !== null,
            ),
        ];
    }
}
