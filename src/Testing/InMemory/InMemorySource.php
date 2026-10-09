<?php

declare(strict_types=1);

namespace Dniccum\Linear\Testing\InMemory;

use Dniccum\Linear\Contracts\DestinationSettings;
use Dniccum\Linear\Contracts\IssueOwner;
use Dniccum\Linear\Contracts\IssueSource;

/**
 * An {@see IssueSource} that lives in memory: a record with a title, a
 * description and an owner.
 */
final class InMemorySource implements IssueSource
{
    /**
     * @param  array<string, mixed>  $changes  What {@see self::changes()} reports.
     */
    public function __construct(
        public readonly int|string $id,
        public ?IssueOwner $owner,
        public string $title = 'A record',
        public string $description = 'Something happened.',
        public array $changes = [],
        public ?DestinationSettings $destinationOverride = null,
        public readonly string $type = 'record',
    ) {}

    public function sourceType(): string
    {
        return $this->type;
    }

    public function sourceId(): int|string
    {
        return $this->id;
    }

    public function owner(): ?IssueOwner
    {
        return $this->owner;
    }

    public function destinationOverride(): ?DestinationSettings
    {
        return $this->destinationOverride;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function comment(string $event, array $context = []): string
    {
        return "{$this->title} was {$event}.";
    }

    public function changes(): array
    {
        return $this->changes;
    }
}
