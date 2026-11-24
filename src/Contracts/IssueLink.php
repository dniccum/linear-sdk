<?php

declare(strict_types=1);

namespace Dniccum\Linear\Contracts;

use Dniccum\Linear\Data\Issue;
use Dniccum\Linear\Data\IssuePayload;
use Dniccum\Linear\Enums\LinearIssueSource;
use Dniccum\Linear\Enums\LinearSyncStatus;

/**
 * The Linear issue filed for a record, and the state of filing it.
 *
 * The issue ID is generated locally before the first attempt and reused on
 * every retry, and the payload is frozen at the same time, so a retry always
 * re-sends the exact same issue. Every mutating method persists immediately.
 * There must be at most one link per source record.
 */
interface IssueLink
{
    public function linkId(): int|string;

    public function syncStatus(): LinearSyncStatus;

    public function isSynced(): bool;

    /**
     * How many times creating the issue was attempted.
     */
    public function attemptCount(): int;

    /**
     * The client-generated Linear issue ID.
     */
    public function issueId(): string;

    /**
     * The workspace the issue targets.
     */
    public function organizationId(): string;

    /**
     * Linear's human-friendly key ("SUP-42"), once the issue exists.
     */
    public function issueIdentifier(): ?string;

    public function issueUrl(): ?string;

    public function issuePayload(): IssuePayload;

    /**
     * Start filing again: a failed link is reused so its issue ID carries
     * over. Pass $newIssueId (and the attempts restart at zero) when the old
     * ID can never exist in the new workspace. Resets the status to pending
     * and clears the error.
     */
    public function restart(IssueOwner $owner, Connection $connection, LinearIssueSource $source, IssuePayload $payload, ?string $newIssueId = null): void;

    /**
     * Failed → pending, error cleared.
     */
    public function requeue(): void;

    public function recordAttempt(): void;

    /**
     * Store the composed title and description.
     */
    public function freezePayload(IssuePayload $payload): void;

    /**
     * The issue exists in Linear.
     */
    public function markSynced(Connection $connection, Issue $issue): void;

    /**
     * Give up: status failed, with the reason.
     */
    public function markFailed(string $message): void;

    /**
     * Keep the status, remember the reason (a retry is coming).
     */
    public function recordError(string $message): void;
}
