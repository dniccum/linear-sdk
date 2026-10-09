<?php

declare(strict_types=1);

namespace Dniccum\Linear\Testing\InMemory;

use Dniccum\Linear\Contracts\CommentDelivery;
use Dniccum\Linear\Contracts\IssueLink;
use Dniccum\Linear\Contracts\SyncQueue;
use Dniccum\Linear\Services\LinearIssueSync;

/**
 * A {@see SyncQueue} that only remembers what was queued; {@see self::work()}
 * plays the part of the worker.
 */
final class InMemoryQueue implements SyncQueue
{
    /**
     * @var list<array{type: 'issue'|'comment', id: int|string}>
     */
    public array $messages = [];

    public function issue(IssueLink $link, bool $afterCommit = false): void
    {
        $this->messages[] = ['type' => 'issue', 'id' => $link->linkId()];
    }

    public function comment(CommentDelivery $delivery, bool $afterCommit = false): void
    {
        $this->messages[] = ['type' => 'comment', 'id' => $delivery->deliveryId()];
    }

    /**
     * Process queued messages until none is left, as a worker would. A
     * message that asks to be retried later is retried straight away (the
     * delay is only reported through $delays), up to the sync's attempt limit.
     *
     * @param  list<int>  $delays  Receives the delay of every retry that was requested.
     */
    public function work(LinearIssueSync $sync, array &$delays = []): void
    {
        while ($this->messages !== []) {
            $message = array_shift($this->messages);
            $attempt = 1;

            do {
                $delay = $message['type'] === 'issue'
                    ? $sync->processIssue($message['id'], $attempt)
                    : $sync->processComment($message['id'], $attempt);

                if ($delay !== null) {
                    $delays[] = $delay;
                }
            } while ($delay !== null && ++$attempt <= LinearIssueSync::MAX_ATTEMPTS);
        }
    }
}
