<?php

declare(strict_types=1);

namespace Dniccum\Linear\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Workbench\App\Models\Ticket;

/**
 * A model a comment originates from.
 *
 * @property int $id
 * @property int $ticket_id
 * @property string $body
 */
class Reply extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['ticket_id', 'body'];

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
