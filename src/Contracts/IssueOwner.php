<?php

declare(strict_types=1);

namespace Dniccum\Linear\Contracts;

/**
 * Whoever connects a Linear workspace: usually a user or a team.
 */
interface IssueOwner
{
    /**
     * A stable name for the owner's kind (a morph alias, an entity name).
     */
    public function ownerType(): string;

    public function ownerId(): int|string;

    public function connection(): ?Connection;

    public function destination(): ?DestinationSettings;
}
