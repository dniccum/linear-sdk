<?php

declare(strict_types=1);

namespace Dniccum\Linear\Testing\InMemory;

use Dniccum\Linear\Contracts\CommentDelivery;
use Dniccum\Linear\Contracts\IssueLink;
use Dniccum\Linear\Enums\LinearSyncStatus;

/**
 * A {@see CommentDelivery} that lives in memory.
 */
final class InMemoryCommentDelivery implements CommentDelivery
{
    public LinearSyncStatus $status = LinearSyncStatus::Pending;

    public int $attempts = 0;

    public ?string $lastError = null;

    public bool $justQueued = true;

    public function __construct(
        public readonly int $id,
        public readonly IssueLink $link,
        public readonly string $linearCommentId,
        public readonly string $body,
    ) {}

    public function deliveryId(): int
    {
        return $this->id;
    }

    public function parentLink(): IssueLink
    {
        return $this->link;
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
        return $this->linearCommentId;
    }

    public function commentBody(): string
    {
        return $this->body;
    }

    public function wasJustQueued(): bool
    {
        return $this->justQueued;
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

    public function markDelivered(): void
    {
        $this->status = LinearSyncStatus::Synced;
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
