<?php

declare(strict_types=1);

namespace Dniccum\Linear\Jobs;

use Dniccum\Linear\Services\LinearIssueSync;
use Dniccum\Linear\Support\Json;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Post a queued comment on its linked Linear issue, retrying the same way as
 * {@see CreateLinearIssue}. The work itself is
 * {@see LinearIssueSync::processComment()}.
 */
class DeliverLinearComment implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = LinearIssueSync::MAX_ATTEMPTS;

    /**
     * @var list<int>
     */
    public array $backoff = LinearIssueSync::BACKOFF;

    public function __construct(
        public int $linearCommentDeliveryId,
    ) {
        $this->onConnection(Json::nullableString(config('linear.queue.connection')));
        $this->onQueue(Json::nullableString(config('linear.queue.name')));
    }

    public function handle(LinearIssueSync $sync): void
    {
        $delay = $sync->processComment($this->linearCommentDeliveryId, $this->attempts(), $this->tries);

        if ($delay !== null) {
            $this->release($this->backoff[$this->attempts() - 1] ?? $delay);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(LinearIssueSync::class)->failComment($this->linearCommentDeliveryId, $exception);
    }
}
