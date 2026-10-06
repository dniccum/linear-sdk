<?php

declare(strict_types=1);

namespace Dniccum\Linear\Data;

/**
 * A destination confirmed against the connected workspace, with the team it
 * resolved to.
 */
final readonly class ResolvedDestination
{
    public function __construct(
        public Destination $destination,
        public Team $team,
    ) {}
}
