<?php

declare(strict_types=1);

namespace Dniccum\Linear\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Workbench\App\Models\Ticket;
use Workbench\App\Models\User;

/**
 * Names its owner explicitly instead of through a `user` relation.
 */
class OrphanTicket extends Ticket
{
    protected $table = 'tickets';

    public static ?User $owner = null;

    public function linearOwner(): ?Model
    {
        return self::$owner;
    }
}
