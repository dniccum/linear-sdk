<?php

declare(strict_types=1);

namespace Dniccum\Linear\Testing\InMemory;

use Dniccum\Linear\Contracts\Connection;
use Dniccum\Linear\Contracts\IssueLink;
use Dniccum\Linear\Contracts\IssueOwner;
use Dniccum\Linear\Contracts\IssueSource;
use Dniccum\Linear\Data\Issue;
use Dniccum\Linear\Data\IssuePayload;
use Dniccum\Linear\Enums\LinearIssueSource;
use Dniccum\Linear\Enums\LinearSyncStatus;

/**
 * An {@see IssueLink} that lives in memory.
 */
final class InMemoryIssueLink implements IssueLink
{
    public LinearSyncStatus $status = LinearSyncStatus::Pending;

    public int $attempts = 0;

    public ?string $identifier = null;

    public ?string $url = null;

    public ?string $lastError = null;

    public function __construct(
        public readonly int $id,
        public readonly IssueSource $source,
        public IssueOwner $owner,
        public string $organization,
        public LinearIssueSource $kind,
        public string $linearIssueId,
        public IssuePayload $payload,
    ) {}

    public function linkId(): int
    {
        return $this->id;
    }

    public function syncStatus(): LinearSyncStatus
    {
        return $this->status;
    }

    public function isSynced(): bool
    {
        return $this->status === LinearSyncStatus::Synced;
    }

    public function attemptCount(): int
    {
        return $this->attempts;
    }

    public function issueId(): string
    {
        return $this->linearIssueId;
    }

    public function organizationId(): string
    {
        return $this->organization;
    }

    public function issueIdentifier(): ?string
    {
        return $this->identifier;
    }

    public function issueUrl(): ?string
    {
        return $this->url;
    }

    public function issuePayload(): IssuePayload
    {
        return $this->payload;
    }

    public function restart(IssueOwner $owner, Connection $connection, LinearIssueSource $source, IssuePayload $payload, ?string $newIssueId = null): void
    {
        if ($newIssueId !== null) {
            $this->linearIssueId = $newIssueId;
            $this->attempts = 0;
        }

        $this->owner = $owner;
        $this->organization = $connection->organizationId();
        $this->kind = $source;
        $this->status = LinearSyncStatus::Pending;
        $this->payload = $payload;
        $this->lastError = null;
    }

    public function requeue(): void
    {
        $this->status = LinearSyncStatus::Pending;
        $this->lastError = null;
    }

    public function recordAttempt(): void
    {
        $this->attempts++;
    }

    public function freezePayload(IssuePayload $payload): void
    {
        $this->payload = $payload;
    }

    public function markSynced(Connection $connection, Issue $issue): void
    {
        $this->status = LinearSyncStatus::Synced;
        $this->identifier = $issue->identifier;
        $this->url = $issue->url;
        $this->lastError = null;
    }

    public function markFailed(string $message): void
    {
        $this->status = LinearSyncStatus::Failed;
        $this->lastError = $message;
    }

    public function recordError(string $message): void
    {
        $this->lastError = $message;
    }
}
