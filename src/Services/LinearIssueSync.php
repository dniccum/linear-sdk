<?php

declare(strict_types=1);

namespace Dniccum\Linear\Services;

use Dniccum\Linear\Contracts\CommentDelivery;
use Dniccum\Linear\Contracts\Connection;
use Dniccum\Linear\Contracts\ErrorReporter;
use Dniccum\Linear\Contracts\IssueLink;
use Dniccum\Linear\Contracts\IssueOwner;
use Dniccum\Linear\Contracts\IssueSource;
use Dniccum\Linear\Contracts\LinearStore;
use Dniccum\Linear\Contracts\SyncQueue;
use Dniccum\Linear\Data\Comment;
use Dniccum\Linear\Data\Destination;
use Dniccum\Linear\Data\Issue;
use Dniccum\Linear\Data\IssuePayload;
use Dniccum\Linear\Enums\LinearIssueSource;
use Dniccum\Linear\Enums\LinearSyncStatus;
use Dniccum\Linear\Events\LinearCommentDelivered;
use Dniccum\Linear\Events\LinearIssueCreated;
use Dniccum\Linear\Events\LinearIssueFailed;
use Dniccum\Linear\Exceptions\DuplicateIssueLinkException;
use Dniccum\Linear\Exceptions\LinearApiException;
use Dniccum\Linear\LinearConfig;
use Dniccum\Linear\Support\Json;
use Dniccum\Linear\Support\LogErrorReporter;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;

/**
 * Files records as Linear issues and mirrors later events as comments.
 *
 * Every outbound write is recorded before it is queued, carries a
 * client-generated Linear ID, and is looked up before being re-sent, so a
 * record is filed at most once and each comment is posted at most once no
 * matter how many times a job is retried.
 *
 * Framework-agnostic: records are {@see IssueSource}s, state lives behind the
 * {@see LinearStore} and background work behind the {@see SyncQueue}. A worker
 * calls {@see self::processIssue()} and {@see self::processComment()}.
 */
class LinearIssueSync
{
    /**
     * How many times a job is attempted before the work is marked failed.
     */
    public const int MAX_ATTEMPTS = 5;

    /**
     * Seconds to wait before attempt 2, 3, 4 and 5 (the last value repeats).
     *
     * @var list<int>
     */
    public const array BACKOFF = [30, 120, 600, 1800];

    /**
     * The snake_case destination fields `sendManually()` overrides accept.
     *
     * @var list<string>
     */
    protected const array DESTINATION_FIELDS = ['team_id', 'project_id', 'state_id', 'label_ids', 'priority', 'assignee_id'];

    protected ErrorReporter $reporter;

    public function __construct(
        protected LinearClient $client,
        protected LinearStore $store,
        protected SyncQueue $queue,
        protected LinearConfig $config,
        protected ?EventDispatcherInterface $events = null,
        ?ErrorReporter $reporter = null,
    ) {
        $this->reporter = $reporter ?? new LogErrorReporter;
    }

    /**
     * React to a lifecycle event ("created", "updated" or "deleted") on a
     * source record. Call it once the record's own settings say the event
     * should act on Linear.
     */
    public function handleEvent(IssueSource $source, string $event): void
    {
        match ($event) {
            'created' => $this->fileAutomatically($source),
            'updated' => $this->handleUpdated($source),
            'deleted' => $this->handleDeleted($source),
            default => null,
        };
    }

    /**
     * File a newly created record through its owner's destination, if that is
     * set to send automatically and the owner's connection can use it.
     */
    public function fileAutomatically(IssueSource $source): ?IssueLink
    {
        $owner = $source->owner();

        if ($owner === null) {
            return null;
        }

        $connection = $owner->connection();
        $destination = $source->destinationOverride() ?? $owner->destination();

        if ($connection === null || $destination === null || ! $destination->appliesTo($connection)) {
            return null;
        }

        // Title and description are composed on the first push, once the
        // record's related data has been stored alongside it.
        return $this->createLink($source, $owner, $connection, LinearIssueSource::Automatic, new IssuePayload($destination->destination()));
    }

    /**
     * File a record with a destination and content chosen by the caller. An
     * existing link must have failed; it is reused so its issue ID carries
     * over. If Linear turns out to have created that issue after all, it is
     * adopted (check isSynced() on the result) and the new payload is not
     * applied.
     *
     * @param  array<string, mixed>  $overrides  snake_case destination fields (team_id, project_id, state_id, label_ids, priority, assignee_id) plus optional title and description.
     *
     * @throws LinearApiException
     */
    public function sendManually(IssueSource $source, array $overrides = []): IssueLink
    {
        $owner = $source->owner()
            ?? throw new LinearApiException('This record has no Linear owner, so there is no connection to send it through.', LinearApiException::INVALID_REQUEST);

        $connection = $owner->connection() ?? throw LinearApiException::notConnected();

        if (! $connection->isActive()) {
            throw new LinearApiException('Your Linear connection needs to be reauthorized. Reconnect Linear to continue.', LinearApiException::AUTHENTICATION);
        }

        $link = $this->linkFor($source);

        if ($link !== null && $link->syncStatus() !== LinearSyncStatus::Failed) {
            throw new LinearApiException(
                $link->isSynced()
                    ? 'This record is already linked to '.$this->identifier($link).'.'
                    : 'A Linear issue is already being created for this record.',
                LinearApiException::INVALID_REQUEST,
            );
        }

        $base = ($source->destinationOverride() ?? $owner->destination())?->destination();
        $destination = Destination::fromArray([
            ...($base?->toArray() ?? []),
            ...Arr::only($overrides, self::DESTINATION_FIELDS),
        ]);

        if ($destination->teamId === '') {
            throw new LinearApiException('No Linear team is configured. Save a destination or pass a team_id.', LinearApiException::INVALID_REQUEST);
        }

        return $this->fileManually($source, $owner, $connection, new IssuePayload(
            $destination,
            Json::nullableString($overrides['title'] ?? null) ?? $source->title(),
            is_string($overrides['description'] ?? null) ? $overrides['description'] : $source->description(),
        ));
    }

    /**
     * @throws LinearApiException
     */
    public function fileManually(IssueSource $source, IssueOwner $owner, Connection $connection, IssuePayload $payload): IssueLink
    {
        $link = $this->linkFor($source);

        if ($link === null) {
            return $this->createLink($source, $owner, $connection, LinearIssueSource::Manual, $payload);
        }

        $newIssueId = null;

        // Filing into a different workspace than the failed attempt starts
        // over: the old issue ID can only ever exist in the old workspace.
        if ($link->organizationId() !== $connection->organizationId()) {
            $newIssueId = (string) Str::uuid();
        } elseif ($link->attemptCount() > 0) {
            // An earlier attempt may have created the issue before its
            // response was lost. Adopt it as-is rather than accepting new
            // content and a destination that would never be applied.
            $issue = $this->client->findIssue($connection, $link->issueId());

            if ($issue !== null) {
                $this->markIssueSynced($link, $connection, $issue);

                return $link;
            }
        }

        $link->restart($owner, $connection, LinearIssueSource::Manual, $payload, $newIssueId);

        $this->queue->issue($link, afterCommit: true);

        return $link;
    }

    /**
     * The issue link of a source record, if it has one.
     */
    public function linkFor(IssueSource $source): ?IssueLink
    {
        return $this->store->linkFor($source);
    }

    /**
     * Queue a comment for the issue linked to a source record. Returns null
     * when the record has no issue. Comments on an issue that is still being
     * filed wait until it exists.
     *
     * Pass the record the comment originates from (a reply, a note) as
     * `$origin` to make delivery idempotent: each origin is posted at most
     * once, however often this is called for it.
     */
    public function comment(IssueSource $source, string $body, ?IssueSource $origin = null): ?CommentDelivery
    {
        $link = $this->linkFor($source);

        return $link === null ? null : $this->queueComment($link, $body, $origin);
    }

    public function queueComment(IssueLink $link, string $body, ?IssueSource $origin = null): CommentDelivery
    {
        $delivery = $this->store->createDelivery($link, (string) Str::uuid(), $body, $origin);

        if ($delivery->wasJustQueued() && $link->isSynced()) {
            $this->queue->comment($delivery, afterCommit: true);
        }

        return $delivery;
    }

    /**
     * Requeue whatever failed to reach Linear: the issue itself, or failed
     * comments once the issue exists. Work still pending already has a queued
     * attempt and is left alone so retries never run concurrently.
     */
    public function retry(IssueLink $link): void
    {
        if ($link->syncStatus() === LinearSyncStatus::Failed) {
            $link->requeue();

            // Comments that failed alongside the issue (e.g. on disconnect) go
            // out once it is created.
            foreach ($this->store->deliveries($link, LinearSyncStatus::Failed) as $delivery) {
                $delivery->requeue();
            }

            $this->queue->issue($link);

            return;
        }

        if (! $link->isSynced()) {
            return;
        }

        foreach ($this->store->deliveries($link, LinearSyncStatus::Failed) as $delivery) {
            $delivery->requeue();

            $this->queue->comment($delivery);
        }
    }

    /**
     * What a worker does with a queued "create this issue" message. Returns
     * the number of seconds to wait before trying again, or null when the
     * work is done (or failed for good, or no longer needed).
     *
     * @param  int  $attempt  Which attempt this is, starting at 1.
     */
    public function processIssue(int|string $linkId, int $attempt = 1, int $maxAttempts = self::MAX_ATTEMPTS): ?int
    {
        $link = $this->store->findLink($linkId);

        // Only pending work is sent. A link marked failed (for example by a
        // disconnect) waits for an explicit retry, even if a message was
        // already queued.
        if ($link === null || $link->syncStatus() !== LinearSyncStatus::Pending) {
            return null;
        }

        try {
            $this->pushIssue($link);
        } catch (Throwable $e) {
            $final = ! $this->isRetryable($e) || $attempt >= $maxAttempts;

            $this->recordIssueFailure($link, self::describe($e), $final);

            return $final ? null : self::backoff($attempt);
        }

        return null;
    }

    /**
     * What a worker does with a queued "post this comment" message; see
     * {@see self::processIssue()}.
     *
     * @param  int  $attempt  Which attempt this is, starting at 1.
     */
    public function processComment(int|string $deliveryId, int $attempt = 1, int $maxAttempts = self::MAX_ATTEMPTS): ?int
    {
        $delivery = $this->store->findDelivery($deliveryId);

        // Only pending work is sent; see processIssue().
        if ($delivery === null || $delivery->syncStatus() !== LinearSyncStatus::Pending) {
            return null;
        }

        try {
            $this->pushComment($delivery);
        } catch (Throwable $e) {
            $final = ! $this->isRetryable($e) || $attempt >= $maxAttempts;

            $this->recordCommentFailure($delivery, self::describe($e), $final);

            return $final ? null : self::backoff($attempt);
        }

        return null;
    }

    /**
     * A worker gave up on an issue message (its retries ran out, or the
     * message could not be processed at all): mark the link failed unless it
     * made it to Linear after all.
     */
    public function failIssue(int|string $linkId, ?Throwable $exception = null): void
    {
        $link = $this->store->findLink($linkId);

        if ($link !== null && ! $link->isSynced()) {
            $this->recordIssueFailure($link, self::describe($exception), true);
        }
    }

    /**
     * A worker gave up on a comment message; see {@see self::failIssue()}.
     */
    public function failComment(int|string $deliveryId, ?Throwable $exception = null): void
    {
        $delivery = $this->store->findDelivery($deliveryId);

        if ($delivery !== null && $delivery->syncStatus() !== LinearSyncStatus::Synced) {
            $this->recordCommentFailure($delivery, self::describe($exception), true);
        }
    }

    /**
     * Whether trying again could help. Unexpected exceptions are reported
     * and treated as transient.
     */
    public function isRetryable(?Throwable $e): bool
    {
        if ($e instanceof LinearApiException) {
            return $e->isRetryable();
        }

        if ($e !== null) {
            $this->reporter->report($e);
        }

        return true;
    }

    /**
     * What to record, and show users, for a failure.
     */
    public static function describe(?Throwable $e): string
    {
        return $e instanceof LinearApiException
            ? $e->getMessage()
            : 'Something went wrong while syncing with Linear. Try again shortly.';
    }

    /**
     * Seconds to wait after the given (1-based) attempt failed.
     */
    public static function backoff(int $attempt): int
    {
        return self::BACKOFF[$attempt - 1] ?? self::BACKOFF[array_key_last(self::BACKOFF)];
    }

    /**
     * Create the link's issue in Linear (or adopt it if an earlier attempt
     * already did) and release any comments that were waiting on it.
     *
     * @throws LinearApiException
     */
    public function pushIssue(IssueLink $link): void
    {
        if ($link->isSynced()) {
            return;
        }

        $connection = $this->connectionFor($link);
        $payload = $this->completePayload($link);

        $issue = $link->attemptCount() > 0 ? $this->client->findIssue($connection, $link->issueId()) : null;

        $link->recordAttempt();

        $issue ??= $this->createIssue($connection, $link, $payload);

        $this->markIssueSynced($link, $connection, $issue);
    }

    /**
     * Post a queued comment on the linked issue (or adopt the comment if an
     * earlier attempt already posted it).
     *
     * @throws LinearApiException
     */
    public function pushComment(CommentDelivery $delivery): void
    {
        $link = $delivery->parentLink();

        // Delivered already, or the issue isn't filed yet; pushIssue() queues
        // this delivery once it is.
        if ($delivery->syncStatus() === LinearSyncStatus::Synced || ! $link->isSynced()) {
            return;
        }

        $connection = $this->connectionFor($link);

        $alreadyPosted = $delivery->attemptCount() > 0
            && $this->client->findComment($connection, $delivery->commentId()) !== null;

        $delivery->recordAttempt();

        if (! $alreadyPosted) {
            $this->createComment($connection, $link, $delivery);
        }

        $delivery->markDelivered();

        $connection->markSynced();

        $this->dispatch(new LinearCommentDelivered($delivery));
    }

    public function recordIssueFailure(IssueLink $link, string $message, bool $final): void
    {
        if ($final) {
            $link->markFailed($message);
        } else {
            $link->recordError($message);
        }

        $this->recordConnectionFailure($link, $message);

        if ($final) {
            $this->dispatch(new LinearIssueFailed($link, $message));
        }
    }

    public function recordCommentFailure(CommentDelivery $delivery, string $message, bool $final): void
    {
        if ($final) {
            $delivery->markFailed($message);
        } else {
            $delivery->recordError($message);
        }

        $this->recordConnectionFailure($delivery->parentLink(), $message);
    }

    /**
     * A record that already has an issue: post a comment listing what changed
     * when `on_update` says so. A record without one is filed now (a record
     * whose events list only "updated" is filed on its first save).
     */
    protected function handleUpdated(IssueSource $source): void
    {
        $link = $this->linkFor($source);

        if ($link === null) {
            $this->fileAutomatically($source);

            return;
        }

        $changes = $source->changes();

        if ($this->config->onUpdate === 'comment' && $changes !== []) {
            $this->queueComment($link, $source->comment('updated', ['changes' => $changes]));
        }
    }

    protected function handleDeleted(IssueSource $source): void
    {
        $link = $this->linkFor($source);

        if ($link !== null && $this->config->onDelete === 'comment') {
            $this->queueComment($link, $source->comment('deleted'));
        }
    }

    protected function markIssueSynced(IssueLink $link, Connection $connection, Issue $issue): void
    {
        $link->markSynced($connection, $issue);

        $connection->markSynced();

        foreach ($this->store->deliveries($link, LinearSyncStatus::Pending) as $delivery) {
            $this->queue->comment($delivery);
        }

        $this->dispatch(new LinearIssueCreated($link));
    }

    protected function createLink(IssueSource $source, IssueOwner $owner, Connection $connection, LinearIssueSource $kind, IssuePayload $payload): IssueLink
    {
        try {
            $link = $this->store->createLink($source, $owner, $connection, $kind, (string) Str::uuid(), $payload);
        } catch (DuplicateIssueLinkException) {
            // Another process linked this record first.
            return $this->store->linkFor($source) ?? throw new DuplicateIssueLinkException;
        }

        $this->queue->issue($link, afterCommit: true);

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
    protected function connectionFor(IssueLink $link): Connection
    {
        $connection = $this->store->connectionFor($link);

        if ($connection === null) {
            throw LinearApiException::notConnected();
        }

        if ($connection->organizationId() !== $link->organizationId()) {
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
    protected function completePayload(IssueLink $link): IssuePayload
    {
        $payload = $link->issuePayload();

        if (! $payload->hasContent()) {
            $source = $this->store->sourceFor($link)
                ?? throw new LinearApiException('The record this issue was created for no longer exists.', LinearApiException::INVALID_REQUEST);

            $payload = $payload->withContent($source->title(), $source->description());

            $link->freezePayload($payload);
        }

        return $payload;
    }

    /**
     * @throws LinearApiException
     */
    protected function createIssue(Connection $connection, IssueLink $link, IssuePayload $payload): Issue
    {
        try {
            return $this->client->createIssue($connection, $link->issueId(), $payload);
        } catch (LinearApiException $e) {
            // A concurrent attempt may have won with the same issue ID.
            if ($e->reason === LinearApiException::INVALID_REQUEST
                && ($issue = $this->client->findIssue($connection, $link->issueId())) !== null) {
                return $issue;
            }

            throw $e;
        }
    }

    /**
     * @throws LinearApiException
     */
    protected function createComment(Connection $connection, IssueLink $link, CommentDelivery $delivery): Comment
    {
        try {
            return $this->client->createComment($connection, $delivery->commentId(), $link->issueId(), $delivery->commentBody());
        } catch (LinearApiException $e) {
            if ($e->reason === LinearApiException::INVALID_REQUEST
                && ($comment = $this->client->findComment($connection, $delivery->commentId())) !== null) {
                return $comment;
            }

            throw $e;
        }
    }

    protected function recordConnectionFailure(IssueLink $link, string $message): void
    {
        $connection = $this->store->connectionFor($link);

        if ($connection !== null && $connection->organizationId() === $link->organizationId() && $connection->isActive()) {
            $connection->markFailed($message);
        }
    }

    /**
     * The identifier of a synced link's issue, for messages.
     */
    private function identifier(IssueLink $link): string
    {
        return $link->issueIdentifier() ?? $link->issueId();
    }

    private function dispatch(object $event): void
    {
        $this->events?->dispatch($event);
    }
}
