<?php

declare(strict_types=1);

namespace Dniccum\Linear\Concerns;

use Dniccum\Linear\Enums\LinearSyncStatus;
use Dniccum\Linear\Exceptions\LinearApiException;
use Dniccum\Linear\Laravel\EloquentSync;
use Dniccum\Linear\Models\LinearCommentDelivery;
use Dniccum\Linear\Models\LinearDestination;
use Dniccum\Linear\Models\LinearIssueLink;
use Dniccum\Linear\Observers\LinearModelObserver;
use Dniccum\Linear\Services\IssueComposer;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Makes a model fileable as a Linear issue.
 *
 * Which Eloquent events act on the model is decided by linearEvents(); the
 * issue is filed automatically when the owner (see linearOwner()) has an
 * active connection and a destination in automatic mode, and can always be
 * filed by hand with sendToLinear().
 *
 * Override any `linear*()` hook below to customise behaviour. Exposes the
 * `linear_issue_url`, `linear_issue_identifier` and `linear_sync_status`
 * attributes, which are safe to add to `$appends` (eager load
 * `linearIssueLink` to avoid N+1 queries).
 *
 * @phpstan-require-extends Model
 */
trait CreatesLinearIssues
{
    /**
     * Wire the model's lifecycle events to the {@see LinearModelObserver}.
     * (Model::observe() cannot be used while a model boots.)
     */
    public static function bootCreatesLinearIssues(): void
    {
        static::created(fn (Model $model) => app(LinearModelObserver::class)->created($model));
        static::updated(fn (Model $model) => app(LinearModelObserver::class)->updated($model));
        static::deleted(fn (Model $model) => app(LinearModelObserver::class)->deleted($model));
    }

    /**
     * @return MorphOne<LinearIssueLink, $this>
     */
    public function linearIssueLink(): MorphOne
    {
        return $this->morphOne(LinearIssueLink::class, 'linkable');
    }

    /**
     * @return Attribute<string|null, never>
     */
    protected function linearIssueUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->resolveLinearIssueLink()?->linear_issue_url);
    }

    /**
     * @return Attribute<string|null, never>
     */
    protected function linearIssueIdentifier(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->resolveLinearIssueLink()?->linear_issue_identifier);
    }

    /**
     * "pending", "synced" or "failed"; null when no issue was ever filed.
     *
     * @return Attribute<LinearSyncStatus|null, never>
     */
    protected function linearSyncStatus(): Attribute
    {
        return Attribute::get(fn (): ?LinearSyncStatus => $this->resolveLinearIssueLink()?->status);
    }

    /**
     * File this model in Linear now, regardless of the owner's send mode.
     *
     * The destination is the owner's (or linearDestinationOverride()),
     * overridden by any of `team_id`, `project_id`, `state_id`, `label_ids`,
     * `priority` and `assignee_id` in $overrides; `title` and `description`
     * replace the generated content. The issue is created by a queued job.
     * An existing link must have failed.
     *
     * @param  array<string, mixed>  $overrides
     *
     * @throws LinearApiException
     */
    public function sendToLinear(array $overrides = []): LinearIssueLink
    {
        return app(EloquentSync::class)->sendManually($this, $overrides);
    }

    /**
     * Requeue whatever failed to reach Linear for this model: its issue, or
     * comments on it. Does nothing when there is nothing to retry.
     */
    public function retryLinear(): void
    {
        $link = $this->resolveLinearIssueLink();

        if ($link !== null) {
            app(EloquentSync::class)->retry($link);
        }
    }

    /**
     * Post a comment on this model's Linear issue (queued; null when it has
     * none). Pass the model the comment originates from, such as a reply, as
     * `$origin` to deliver each origin at most once.
     */
    public function commentOnLinear(string $body, ?Model $origin = null): ?LinearCommentDelivery
    {
        return app(EloquentSync::class)->comment($this, $body, $origin);
    }

    /**
     * The Eloquent events that act on this model: "created", "updated" and/or
     * "deleted". Created and updated file the model if it has no issue yet;
     * once it has one, updated and deleted post a comment according to
     * `linear.on_update` and `linear.on_delete`.
     *
     * @return list<string>
     */
    public function linearEvents(): array
    {
        return ['created'];
    }

    /**
     * The model whose Linear connection and destination apply: by default the
     * `user` relation, if this model has one. Return null to opt a record out.
     */
    public function linearOwner(): ?Model
    {
        if (! $this->isRelation('user')) {
            return null;
        }

        $user = $this->getRelationValue('user');

        return $user instanceof Model ? $user : null;
    }

    /**
     * A destination that replaces the owner's for this model. It may be an
     * unsaved instance: `new LinearDestination(['team_id' => '...'])`.
     */
    public function linearDestinationOverride(): ?LinearDestination
    {
        return null;
    }

    /**
     * The issue title.
     */
    public function linearTitle(): string
    {
        return app(IssueComposer::class)->defaultTitle($this);
    }

    /**
     * The issue description, as markdown.
     */
    public function linearDescription(): string
    {
        return app(IssueComposer::class)->defaultDescription($this);
    }

    /**
     * The comment posted for a lifecycle event ("updated" or "deleted").
     *
     * @param  array<string, mixed>  $context
     */
    public function linearComment(string $event, array $context = []): string
    {
        return app(IssueComposer::class)->defaultComment($this, $event, $context);
    }

    private function resolveLinearIssueLink(): ?LinearIssueLink
    {
        $link = $this->getRelationValue('linearIssueLink');

        return $link instanceof LinearIssueLink ? $link : null;
    }
}
