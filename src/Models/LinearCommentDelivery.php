<?php

declare(strict_types=1);

namespace Dniccum\Linear\Models;

use Carbon\CarbonInterface;
use Dniccum\Linear\Contracts\CommentDelivery;
use Dniccum\Linear\Contracts\IssueLink;
use Dniccum\Linear\Database\Factories\LinearCommentDeliveryFactory;
use Dniccum\Linear\Enums\LinearSyncStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A comment mirrored onto a linked Linear issue.
 *
 * When the comment originates from another model (a reply, a note) the
 * source morph points at it and is unique, so each origin is delivered at most
 * once. Ad hoc comments have no source.
 *
 * @property int $id
 * @property int $linear_issue_link_id
 * @property string|null $source_type
 * @property int|string|null $source_id
 * @property LinearSyncStatus $status
 * @property string $linear_comment_id
 * @property string $body
 * @property int $attempts
 * @property string|null $last_error
 * @property CarbonInterface|null $delivered_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read LinearIssueLink $issueLink
 * @property-read Model|null $source
 */
class LinearCommentDelivery extends Model implements CommentDelivery
{
    /** @use HasFactory<LinearCommentDeliveryFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'linear_issue_link_id',
        'source_type',
        'source_id',
        'status',
        'linear_comment_id',
        'body',
        'attempts',
        'last_error',
        'delivered_at',
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
        return config()->string('linear.table_prefix', 'linear_').'comment_deliveries';
    }

    /**
     * @return BelongsTo<LinearIssueLink, $this>
     */
    public function issueLink(): BelongsTo
    {
        return $this->belongsTo(LinearIssueLink::class, 'linear_issue_link_id');
    }

    /**
     * The model the comment originates from, when it has one.
     *
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function deliveryId(): int|string
    {
        $key = $this->getKey();

        return is_int($key) || is_string($key) ? $key : '';
    }

    public function parentLink(): IssueLink
    {
        return $this->issueLink;
    }

    public function syncStatus(): LinearSyncStatus
    {
        return $this->status;
    }

    public function attemptCount(): int
    {
        return $this->attempts;
    }

    public function commentId(): string
    {
        return $this->linear_comment_id;
    }

    public function commentBody(): string
    {
        return $this->body;
    }

    public function wasJustQueued(): bool
    {
        return $this->wasRecentlyCreated;
    }

    public function requeue(): void
    {
        $this->forceFill(['status' => LinearSyncStatus::Pending, 'last_error' => null])->save();
    }

    public function recordAttempt(): void
    {
        $this->increment('attempts');
    }

    public function markDelivered(): void
    {
        $this->forceFill([
            'status' => LinearSyncStatus::Synced,
            'last_error' => null,
            'delivered_at' => now(),
        ])->save();
    }

    public function markFailed(string $message): void
    {
        $this->forceFill(['status' => LinearSyncStatus::Failed, 'last_error' => $message])->save();
    }

    public function recordError(string $message): void
    {
        $this->forceFill(['last_error' => $message])->save();
    }

    protected static function newFactory(): LinearCommentDeliveryFactory
    {
        return LinearCommentDeliveryFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => LinearSyncStatus::class,
            'attempts' => 'integer',
            'delivered_at' => 'datetime',
        ];
    }
}
