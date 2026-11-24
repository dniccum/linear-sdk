<?php

declare(strict_types=1);

namespace Dniccum\Linear\Testing\InMemory;

use Dniccum\Linear\Contracts\Connection;
use Dniccum\Linear\Contracts\DestinationSettings;
use Dniccum\Linear\Data\Destination;

/**
 * A {@see DestinationSettings} that lives in memory.
 */
final readonly class InMemoryDestination implements DestinationSettings
{
    public function __construct(
        private Destination $destination,
        private bool $automatic = true,
    ) {}

    public function appliesTo(?Connection $connection): bool
    {
        return $this->automatic && $connection !== null && $connection->isActive();
    }

    public function destination(): Destination
    {
        return $this->destination;
    }
}
