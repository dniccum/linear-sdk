<?php

declare(strict_types=1);

namespace Dniccum\Linear\Actions;

use Dniccum\Linear\Models\LinearDestination;
use Illuminate\Database\Eloquent\Model;

/**
 * Remove the owner's destination. Records are no longer filed automatically.
 */
class DeleteDestination
{
    public function execute(Model $owner): void
    {
        LinearDestination::query()->whereMorphedTo('owner', $owner)->delete();

        $owner->unsetRelation('linearDestination');
    }
}
