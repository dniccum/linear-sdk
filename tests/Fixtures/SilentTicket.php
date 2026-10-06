<?php

declare(strict_types=1);

namespace Dniccum\Linear\Tests\Fixtures;

use Workbench\App\Models\Ticket;

/**
 * Reacts to no events at all.
 */
class SilentTicket extends Ticket
{
    protected $table = 'tickets';

    /**
     * @return list<string>
     */
    public function linearEvents(): array
    {
        return [];
    }
}
