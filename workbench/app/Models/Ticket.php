<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Dniccum\Linear\Concerns\CreatesLinearIssues;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Workbench\Database\Factories\TicketFactory;

/**
 * An example source model: tickets are filed in the owner's Linear workspace
 * as soon as they are created.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $title
 * @property string|null $body
 * @property string $status
 */
class Ticket extends Model
{
    use CreatesLinearIssues;

    /** @use HasFactory<TicketFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = ['user_id', 'title', 'body', 'status'];

    /**
     * @var list<string>
     */
    protected $appends = ['linear_issue_url', 'linear_issue_identifier', 'linear_sync_status'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function newFactory(): TicketFactory
    {
        return TicketFactory::new();
    }
}
