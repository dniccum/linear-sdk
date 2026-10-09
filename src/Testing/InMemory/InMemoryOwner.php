<?php

declare(strict_types=1);

namespace Dniccum\Linear\Testing\InMemory;

use Dniccum\Linear\Contracts\Connection;
use Dniccum\Linear\Contracts\DestinationSettings;
use Dniccum\Linear\Contracts\IssueOwner;

/**
 * An {@see IssueOwner} that lives in memory.
 */
final class InMemoryOwner implements IssueOwner
{
    public function __construct(
        public readonly int|string $id,
        public ?Connection $connection = null,
        public ?DestinationSettings $destination = null,
        public readonly string $type = 'owner',
    ) {}

    public function ownerType(): string
    {
        return $this->type;
    }

    public function ownerId(): int|string
    {
        return $this->id;
    }

    public function connection(): ?Connection
    {
        return $this->connection;
    }

    public function destination(): ?DestinationSettings
    {
        return $this->destination;
    }
}
