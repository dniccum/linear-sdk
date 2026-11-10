<?php

declare(strict_types=1);

namespace Dniccum\Linear\Contracts;

use Dniccum\Linear\Enums\LinearSyncStatus;

/**
 * A comment mirrored onto a linked Linear issue.
 *
 * When the comment originates from another record (a reply, a note) that
 * record identifies the delivery and is unique, so each origin is delivered
 * at most once. Every mutating method persists immediately.
 */
interface CommentDelivery
{
    public function deliveryId(): int|string;

    public function parentLink(): IssueLink;

    public function syncStatus(): LinearSyncStatus;

    public function attemptCount(): int;

    /**
     * The client-generated Linear comment ID.
     */
    public function commentId(): string;

    public function commentBody(): string;

    /**
     * Whether this call to {@see LinearStore::createDelivery()} created the
     * delivery, as opposed to finding the one made earlier for the same
     * origin.
     */
    public function wasJustQueued(): bool;

    /**
     * Failed → pending, error cleared.
     */
    public function requeue(): void;

    public function recordAttempt(): void;

    public function markDelivered(): void;

    public function markFailed(string $message): void;

    public function recordError(string $message): void;
}
