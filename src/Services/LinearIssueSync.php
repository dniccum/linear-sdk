<?php

declare(strict_types=1);

namespace Dniccum\Linear\Services;

use Dniccum\Linear\Data\Comment;
use Dniccum\Linear\Data\Destination;
use Dniccum\Linear\Data\Issue;
use Dniccum\Linear\Data\IssuePayload;
use Dniccum\Linear\Enums\LinearIssueSource;
use Dniccum\Linear\Enums\LinearSyncStatus;
use Dniccum\Linear\Events\LinearCommentDelivered;
use Dniccum\Linear\Events\LinearIssueCreated;
use Dniccum\Linear\Events\LinearIssueFailed;
use Dniccum\Linear\Exceptions\LinearApiException;
use Dniccum\Linear\Jobs\CreateLinearIssue;
use Dniccum\Linear\Jobs\DeliverLinearComment;
use Dniccum\Linear\Models\LinearCommentDelivery;
use Dniccum\Linear\Models\LinearConnection;
use Dniccum\Linear\Models\LinearIssueLink;
use Dniccum\Linear\Support\Json;
use Dniccum\Linear\Support\ModelHooks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Files models as Linear issues and mirrors later events as comments.
 *
 * Every outbound write is recorded before it is queued, carries a
 * client-generated Linear ID, and is looked up before being re-sent, so a
 * model is filed at most once and each comment is posted at most once no
 * matter how many times a job is retried.
 */
class LinearIssueSync
{
    /**
     * The snake_case destination fields `sendToLinear()` overrides accept.
     *
     * @var list<string>
     */
    protected const array DESTINATION_FIELDS = ['team_id', 'project_id', 'state_id', 'label_ids', 'priority', 'assignee_id'];

    public function __construct(
        protected LinearClient $client,
        protected IssueComposer $composer,
    ) {}

    /**
     * React to an Eloquent lifecycle event on a source model. Called by the
     * model observer once the model's `linearEvents()` includes the event.
     */
    public function handleEvent(Model $source, string $event): void
    {
        match ($event) {
            'created' => $this->fileAutomatically($source),
            'updated' => $this->handleUpdated($source),
            'deleted' => $this->handleDeleted($source),
            default => null,
        };
    }

    /**
     * File a newly created model through its owner's destination, if that is
     * set to send automatically and the owner's connection can use it.
     */
    public function fileAutomatically(Model $source): ?LinearIssueLink
    {
        $owner = ModelHooks::owner($source);

        if ($owner === null) {
            return null;
        }

        $connection = ModelHooks::connection($owner);
        $destination = ModelHooks::destinationOverride($source) ?? ModelHooks::destination($owner);

        if ($connection === null || $destination === null || ! $destination->appliesTo($connection)) {
            return null;
        }

        // Title and description are composed on the first push, once the
        // model's related records have been stored alongside it.
        return $this->createLink($source, $owner, $connection, LinearIssueSource::Automatic, new IssuePayload($destination->destination()));
    }

    /**
     * File a model with a destination and content chosen by the caller. An
     * existing link must have failed; it is reused so its issue ID carries
     * over. If Linear turns out to have created that issue after all, it is
     * adopted (check isSynced() on the result) and the new payload is not
     * applied.
     *
     * @param  array<string, mixed>  $overrides  snake_case destination fields (team_id, project_id, state_id, label_ids, priority, assignee_id) plus optional title and description.
     *
     * @throws LinearApiException
     */
    public function sendManually(Model $source, array $overrides = []): LinearIssueLink
    {
        $owner = ModelHooks::owner($source)
            ?? throw new LinearApiException('This record has no Linear owner, so there is no connection to send it through.', LinearApiException::INVALID_REQUEST);

        $connection = ModelHooks::connection($owner) ?? throw LinearApiException::notConnected();

        if (! $connection->isActive()) {
            throw new LinearApiException('Your Linear connection needs to be reauthorized. Reconnect Linear to continue.', LinearApiException::AUTHENTICATION);
        }

        $link = $this->linkFor($source);

        if ($link !== null && $link->status !== LinearSyncStatus::Failed) {
            throw new LinearApiException(
                $link->isSynced()
                    ? "This record is already linked to {$link->linear_issue_identifier}."
                    : 'A Linear issue is already being created for this record.',
                LinearApiException::INVALID_REQUEST,
            );
        }

        $base = (ModelHooks::destinationOverride($source) ?? ModelHooks::destination($owner))?->destination();
        $destination = Destination::fromArray([
            ...($base?->toArray() ?? []),
            ...Arr::only($overrides, self::DESTINATION_FIELDS),
        ]);

        if ($destination->teamId === '') {
            throw new LinearApiException('No Linear team is configured. Save a destination or pass a team_id.', LinearApiException::INVALID_REQUEST);
        }

        return $this->fileManually($source, $owner, $connection, new IssuePayload(
            $destination,
            Json::nullableString($overrides['title'] ?? null) ?? $this->composer->title($source),
            is_string($overrides['description'] ?? null) ? $overrides['description'] : $this->composer->description($source),
        ));
    }

    /**
     * @throws LinearApiException
     */
    public function fileManually(Model $source, Model $owner, LinearConnection $connection, IssuePayload $payload): LinearIssueLink
    {
        $link = $this->linkFor($source);

        if ($link === null) {
            return $this->createLink($source, $owner, $connection, LinearIssueSource::Manual, $payload);
        }

        // Filing into a different workspace than the failed attempt starts
        // over: the old issue ID can only ever exist in the old workspace.
        if ($link->linear_organization_id !== $connection->linear_organization_id) {
            $link->forceFill(['linear_issue_id' => (string) Str::uuid(), 'attempts' => 0]);
        } elseif ($link->attempts > 0) {
            // An earlier attempt may have created the issue before its
            // response was lost. Adopt it as-is rather than accepting new
            // content and a destination that would never be applied.
            $issue = $this->client->findIssue($connection, $link->linear_issue_id);

            if ($issue !== null) {
                $this->markIssueSynced($link, $connection, $issue);

                return $link;
            }
        }

        $link->forceFill([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'connection_id' => $connection->id,
            'linear_organization_id' => $connection->linear_organization_id,
            'source' => LinearIssueSource::Manual,
            'status' => LinearSyncStatus::Pending,
            'payload' => $payload,
            'last_error' => null,
        ])->save();

        CreateLinearIssue::dispatch($link->id)->afterCommit();

        return $link;
    }

    /**
     * The issue link of a source model, if it has one.
     */
    public function linkFor(Model $source): ?LinearIssueLink
    {
        return LinearIssueLink::query()->whereMorphedTo('linkable', $source)->first();
    }

    /**
     * Queue a comment for the issue linked to a source model. Returns null
     * when the model has no issue. Comments on an issue that is still being
     * filed wait until it exists.
     *
     * Pass the model the comment originates from (a reply, a note) as
     * `$origin` to make delivery idempotent: each origin is posted at most
     * once, however often this is called for it.
     */
    public function comment(Model $source, string $body, ?Model $origin = null): ?LinearCommentDelivery
    {
        $link = $this->linkFor($source);

        return $link === null ? null : $this->queueComment($link, $body, $origin);
    }

    public function queueComment(LinearIssueLink $link, string $body, ?Model $origin = null): LinearCommentDelivery
    {
        $attributes = [
            'linear_issue_link_id' => $link->id,
            'linear_comment_id' => (string) Str::uuid(),
            'body' => $body,
        ];

        $delivery = $origin === null
            ? LinearCommentDelivery::query()->create($attributes)
            : LinearCommentDelivery::query()->firstOrCreate(
                ['source_type' => $origin->getMorphClass(), 'source_id' => $origin->getKey()],
                $attributes,
            );

        if ($delivery->wasRecentlyCreated && $link->isSynced()) {
            DeliverLinearComment::dispatch($delivery->id)->afterCommit();
        }

        return $delivery;
    }

    /**
     * Requeue whatever failed to reach Linear: the issue itself, or failed
     * comments once the issue exists. Work still pending already has a queued
     * attempt and is left alone so retries never run concurrently.
     */
    public function retry(LinearIssueLink $link): void
    {
        if ($link->status === LinearSyncStatus::Failed) {
            $link->forceFill(['status' => LinearSyncStatus::Pending, 'last_error' => null])->save();

            // Comments that failed alongside the issue (e.g. on disconnect) go
            // out once it is created.
            $link->commentDeliveries()
                ->where('status', LinearSyncStatus::Failed)
                ->update(['status' => LinearSyncStatus::Pending, 'last_error' => null]);

            CreateLinearIssue::dispatch($link->id);

            return;
        }

        if (! $link->isSynced()) {
            return;
        }

        $failed = $link->commentDeliveries()->where('status', LinearSyncStatus::Failed)->get();

        foreach ($failed as $delivery) {
            $delivery->forceFill(['status' => LinearSyncStatus::Pending, 'last_error' => null])->save();

            DeliverLinearComment::dispatch($delivery->id);
        }
    }

    /**
     * Create the link's issue in Linear (or adopt it if an earlier attempt
     * already did) and release any comments that were waiting on it.
     *
     * @throws LinearApiException
     */
    public function pushIssue(LinearIssueLink $link): void
    {
        if ($link->isSynced()) {
            return;
        }

        $connection = $this->connectionFor($link);
        $payload = $this->completePayload($link);

        $issue = $link->attempts > 0 ? $this->client->findIssue($connection, $link->linear_issue_id) : null;

        $link->increment('attempts');

        $issue ??= $this->createIssue($connection, $link, $payload);

        $this->markIssueSynced($link, $connection, $issue);
    }

    /**
     * Post a queued comment on the linked issue (or adopt the comment if an
     * earlier attempt already posted it).
     *
     * @throws LinearApiException
     */
    public function pushComment(LinearCommentDelivery $delivery): void
    {
        $link = $delivery->issueLink;

        // Delivered already, or the issue isn't filed yet; pushIssue() queues
        // this delivery once it is.
        if ($delivery->status === LinearSyncStatus::Synced || ! $link->isSynced()) {
            return;
        }

        $connection = $this->connectionFor($link);

        $alreadyPosted = $delivery->attempts > 0
            && $this->client->findComment($connection, $delivery->linear_comment_id) !== null;

        $delivery->increment('attempts');

        if (! $alreadyPosted) {
            $this->createComment($connection, $link, $delivery);
        }

        $delivery->forceFill([
            'status' => LinearSyncStatus::Synced,
            'last_error' => null,
            'delivered_at' => now(),
        ])->save();

        $connection->markSynced();

        event(new LinearCommentDelivered($delivery));
    }

    public function recordIssueFailure(LinearIssueLink $link, string $message, bool $final): void
    {
        $link->forceFill([
            'status' => $final ? LinearSyncStatus::Failed : $link->status,
            'last_error' => $message,
        ])->save();

        $this->recordConnectionFailure($link, $message);

        if ($final) {
            event(new LinearIssueFailed($link, $message));
        }
    }

    public function recordCommentFailure(LinearCommentDelivery $delivery, string $message, bool $final): void
    {
        $delivery->forceFill([
            'status' => $final ? LinearSyncStatus::Failed : $delivery->status,
            'last_error' => $message,
        ])->save();

        $this->recordConnectionFailure($delivery->issueLink, $message);
    }

    /**
     * A model that already has an issue: post a comment listing what changed
     * when `linear.on_update` says so. A model without one is filed now (a
     * model whose `linearEvents()` lists only "updated" is filed on its first
     * save).
     */
    protected function handleUpdated(Model $source): void
    {
        $link = $this->linkFor($source);

        if ($link === null) {
            $this->fileAutomatically($source);

            return;
        }

        $changes = Arr::except($source->getChanges(), array_values(array_filter([$source->getUpdatedAtColumn()], fn (?string $column): bool => $column !== null)));

        if (config('linear.on_update') === 'comment' && $changes !== []) {
            $this->queueComment($link, $this->composer->comment($source, 'updated', ['changes' => $changes]));
        }
    }

    protected function handleDeleted(Model $source): void
    {
        $link = $this->linkFor($source);

        if ($link !== null && config('linear.on_delete') === 'comment') {
            $this->queueComment($link, $this->composer->comment($source, 'deleted'));
        }
    }

    protected function markIssueSynced(LinearIssueLink $link, LinearConnection $connection, Issue $issue): void
    {
        $link->forceFill([
            'connection_id' => $connection->id,
            'status' => LinearSyncStatus::Synced,
            'linear_issue_identifier' => $issue->identifier,
            'linear_issue_url' => $issue->url,
            'last_error' => null,
            'synced_at' => now(),
        ])->save();

        $connection->markSynced();

        $waiting = $link->commentDeliveries()->where('status', LinearSyncStatus::Pending)->orderBy('id')->get();

        foreach ($waiting as $delivery) {
            DeliverLinearComment::dispatch($delivery->id);
        }

        event(new LinearIssueCreated($link));
    }

    protected function createLink(Model $source, Model $owner, LinearConnection $connection, LinearIssueSource $sourceKind, IssuePayload $payload): LinearIssueLink
    {
        try {
            $link = LinearIssueLink::query()->create([
                'linkable_type' => $source->getMorphClass(),
                'linkable_id' => $source->getKey(),
                'owner_type' => $owner->getMorphClass(),
                'owner_id' => $owner->getKey(),
                'connection_id' => $connection->id,
                'linear_organization_id' => $connection->linear_organization_id,
                'source' => $sourceKind,
                'linear_issue_id' => (string) Str::uuid(),
                'payload' => $payload,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another process linked this model first.
            return LinearIssueLink::query()->whereMorphedTo('linkable', $source)->firstOrFail();
        }

        CreateLinearIssue::dispatch($link->id)->afterCommit();

        return $link;
    }

    /**
     * The owner's current connection, provided it still belongs to the
     * workspace the link targets. Resolved through the owner rather than the
     * stored connection ID so reconnecting the same workspace resumes
     * syncing.
     *
     * @throws LinearApiException
     */
    protected function connectionFor(LinearIssueLink $link): LinearConnection
    {
        $owner = $link->owner;
        $connection = $owner === null ? null : ModelHooks::connection($owner);

        if ($connection === null) {
            throw LinearApiException::notConnected();
        }

        if ($connection->linear_organization_id !== $link->linear_organization_id) {
            throw new LinearApiException(
                'The connected Linear workspace is not the one this issue was filed in. Reconnect that workspace to continue.',
                LinearApiException::INVALID_REQUEST,
            );
        }

        return $connection;
    }

    /**
     * Freeze the issue's title and description on first use so every retry
     * sends identical content.
     *
     * @throws LinearApiException
     */
    protected function completePayload(LinearIssueLink $link): IssuePayload
    {
        $payload = $link->payload;

        if (! $payload->hasContent()) {
            $source = $link->linkable
                ?? throw new LinearApiException('The record this issue was created for no longer exists.', LinearApiException::INVALID_REQUEST);

            $payload = $payload->withContent($this->composer->title($source), $this->composer->description($source));

            $link->forceFill(['payload' => $payload])->save();
        }

        return $payload;
    }

    /**
     * @throws LinearApiException
     */
    protected function createIssue(LinearConnection $connection, LinearIssueLink $link, IssuePayload $payload): Issue
    {
        try {
            return $this->client->createIssue($connection, $link->linear_issue_id, $payload);
        } catch (LinearApiException $e) {
            // A concurrent attempt may have won with the same issue ID.
            if ($e->reason === LinearApiException::INVALID_REQUEST
                && ($issue = $this->client->findIssue($connection, $link->linear_issue_id)) !== null) {
                return $issue;
            }

            throw $e;
        }
    }

    /**
     * @throws LinearApiException
     */
    protected function createComment(LinearConnection $connection, LinearIssueLink $link, LinearCommentDelivery $delivery): Comment
    {
        try {
            return $this->client->createComment($connection, $delivery->linear_comment_id, $link->linear_issue_id, $delivery->body);
        } catch (LinearApiException $e) {
            if ($e->reason === LinearApiException::INVALID_REQUEST
                && ($comment = $this->client->findComment($connection, $delivery->linear_comment_id)) !== null) {
                return $comment;
            }

            throw $e;
        }
    }

    protected function recordConnectionFailure(LinearIssueLink $link, string $message): void
    {
        $owner = $link->owner;
        $connection = $owner === null ? null : ModelHooks::connection($owner);

        if ($connection !== null && $connection->linear_organization_id === $link->linear_organization_id && $connection->isActive()) {
            $connection->markFailed($message);
        }
    }
}
