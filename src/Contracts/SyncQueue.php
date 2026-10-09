<?php

declare(strict_types=1);

namespace Dniccum\Linear\Contracts;

/**
 * Hands work to a background worker. The worker calls
 * `LinearIssueSync::processIssue()` / `processComment()` with the ID and the
 * attempt number, releases the message again after the returned delay (if
 * any), and calls `failIssue()` / `failComment()` when it gives up.
 */
interface SyncQueue
{
    /**
     * Queue creating the link's issue. $afterCommit asks to wait for the
     * surrounding database transaction, if there is one, so the worker can
     * see the link.
     */
    public function issue(IssueLink $link, bool $afterCommit = false): void;

    /**
     * Queue posting a comment.
     */
    public function comment(CommentDelivery $delivery, bool $afterCommit = false): void;
}
