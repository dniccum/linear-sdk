<?php

declare(strict_types=1);

namespace Dniccum\Linear\Tests\Fixtures;

use Workbench\App\Models\Ticket;

/**
 * Only filed once it is first updated.
 */
class UpdatedOnlyTicket extends Ticket
{
    protected $table = 'tickets';

    /**
     * @return list<string>
     */
    public function linearEvents(): array
    {
        return ['updated'];
    }
}
