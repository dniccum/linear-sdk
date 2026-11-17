<?php

declare(strict_types=1);

namespace Dniccum\Linear\Laravel;

use Dniccum\Linear\Contracts\CommentDelivery;
use Dniccum\Linear\Contracts\IssueLink;
use Dniccum\Linear\Contracts\SyncQueue;
use Dniccum\Linear\Jobs\CreateLinearIssue;
use Dniccum\Linear\Jobs\DeliverLinearComment;

/**
 * Sends the sync's work to Laravel's queue, on the connection and queue named
 * in `linear.queue`.
 */
final class LaravelQueue implements SyncQueue
{
    public function issue(IssueLink $link, bool $afterCommit = false): void
    {
        $pending = CreateLinearIssue::dispatch((int) $link->linkId());

        if ($afterCommit) {
            $pending->afterCommit();
        }
    }

    public function comment(CommentDelivery $delivery, bool $afterCommit = false): void
    {
        $pending = DeliverLinearComment::dispatch((int) $delivery->deliveryId());

        if ($afterCommit) {
            $pending->afterCommit();
        }
    }
}
