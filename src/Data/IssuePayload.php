<?php

declare(strict_types=1);

namespace Dniccum\Linear\Data;

use Dniccum\Linear\Support\Json;
use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Everything sent to Linear to create an issue, frozen on the issue link so
 * retries re-send identical content.
 *
 * An automatic link starts with only a destination; its title and description
 * are composed on the first attempt (see hasContent()). Stored as flat
 * snake_case JSON in the issue links' payload column.
 */
final readonly class IssuePayload extends Data implements Castable
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

    /**
     * @param  array<string>  $arguments
     * @return CastsAttributes<IssuePayload, IssuePayload|array<string, mixed>>
     */
    public static function castUsing(array $arguments): CastsAttributes
    {
        return new class implements CastsAttributes
        {
            public function get(Model $model, string $key, mixed $value, array $attributes): ?IssuePayload
            {
                if (! is_string($value)) {
                    return null;
                }

                return IssuePayload::fromArray(Json::map(json_decode($value, true, flags: JSON_THROW_ON_ERROR)));
            }

            /**
             * @return array<string, string>
             */
            public function set(Model $model, string $key, mixed $value, array $attributes): array
            {
                $payload = match (true) {
                    $value instanceof IssuePayload => $value,
                    is_array($value) => IssuePayload::fromArray(Json::map($value)),
                    default => throw new InvalidArgumentException('The Linear issue payload must be an IssuePayload.'),
                };

                return [$key => json_encode($payload->toArray(), JSON_THROW_ON_ERROR)];
            }
        };
    }
}
