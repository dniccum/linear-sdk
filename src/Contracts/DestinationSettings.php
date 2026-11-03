<?php

declare(strict_types=1);

namespace Dniccum\Linear\Contracts;

use Dniccum\Linear\Data\Destination;

/**
 * An owner's chosen place to file issues, and whether records are sent
 * automatically.
 */
interface DestinationSettings
{
    /**
     * Whether new records should be filed automatically through the given
     * connection: it must be in automatic mode, the connection must be
     * healthy, and it must belong to the workspace the destination lives in.
     */
    public function appliesTo(?Connection $connection): bool;

    public function destination(): Destination;
}
