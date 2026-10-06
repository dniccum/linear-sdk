<?php

declare(strict_types=1);

namespace Dniccum\Linear\Tests\Fixtures;

use Workbench\App\Models\Ticket;

/**
 * Reacts to every lifecycle event.
 */
class EventedTicket extends Ticket
{
    protected $table = 'tickets';

    /**
     * @return list<string>
     */
    public function linearEvents(): array
    {
        return ['created', 'updated', 'deleted'];
    }
}
