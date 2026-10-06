<?php

declare(strict_types=1);

namespace Dniccum\Linear\Models;

use Carbon\CarbonInterface;
use Dniccum\Linear\Data\IssuePayload;
use Dniccum\Linear\Database\Factories\LinearIssueLinkFactory;
use Dniccum\Linear\Enums\LinearIssueSource;
use Dniccum\Linear\Enums\LinearSyncStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The Linear issue filed for a model, and the state of filing it.
 *
 * The issue UUID is generated locally before the first attempt and reused on
 * every retry, and the payload is frozen at the same time, so a retry always
 * re-sends the exact same issue.
 *
 * @property int $id
 * @property string $linkable_type
 * @property int|string $linkable_id
 * @property string|null $owner_type
 * @property int|string|null $owner_id
 * @property int|null $connection_id
 * @property string $linear_organization_id
 * @property LinearIssueSource $source
 * @property LinearSyncStatus $status
 * @property string $linear_issue_id
 * @property string|null $linear_issue_identifier
 * @property string|null $linear_issue_url
 * @property IssuePayload $payload
 * @property int $attempts
 * @property string|null $last_error
 * @property CarbonInterface|null $synced_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Model|null $linkable
 * @property-read Model|null $owner
 */
class LinearIssueLink extends Model
{
    /** @use HasFactory<LinearIssueLinkFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'linkable_type',
        'linkable_id',
        'owner_type',
        'owner_id',
        'connection_id',
        'linear_organization_id',
        'source',
        'status',
        'linear_issue_id',
        'linear_issue_identifier',
        'linear_issue_url',
        'payload',
        'attempts',
        'last_error',
        'synced_at',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'attempts' => 0,
    ];

    public function getTable(): string
    {
        return config()->string('linear.table_prefix', 'linear_').'issue_links';
    }

    /**
     * The model the issue was filed for.
     *
     * @return MorphTo<Model, $this>
     */
    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The model that owns the connection the issue is filed through.
     *
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<LinearConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(LinearConnection::class, 'connection_id');
    }

    /**
     * @return HasMany<LinearCommentDelivery, $this>
     */
    public function commentDeliveries(): HasMany
    {
        return $this->hasMany(LinearCommentDelivery::class, 'linear_issue_link_id');
    }

    public function isSynced(): bool
    {
        return $this->status === LinearSyncStatus::Synced;
    }

    /**
     * Whether a manual retry would do anything: the issue failed, or it was
     * filed but some of its comments failed. Pending work already has an
     * attempt queued.
     */
    public function canRetry(): bool
    {
        return $this->status === LinearSyncStatus::Failed
            || ($this->isSynced() && $this->commentDeliveries()->where('status', LinearSyncStatus::Failed)->exists());
    }

    protected static function newFactory(): LinearIssueLinkFactory
    {
        return LinearIssueLinkFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => LinearIssueSource::class,
            'status' => LinearSyncStatus::class,
            'payload' => IssuePayload::class,
            'attempts' => 'integer',
            'synced_at' => 'datetime',
        ];
    }
}
