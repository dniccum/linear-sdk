<?php

declare(strict_types=1);

namespace Dniccum\Linear\Jobs;

use Dniccum\Linear\Enums\LinearSyncStatus;
use Dniccum\Linear\Models\LinearCommentDelivery;
use Dniccum\Linear\Services\LinearIssueSync;
use Dniccum\Linear\Support\Json;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Post a queued comment on its linked Linear issue, retrying the same way as
 * {@see CreateLinearIssue}.
 */
class DeliverLinearComment implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(
        public int $linearCommentDeliveryId,
    ) {
        $this->onConnection(Json::nullableString(config('linear.queue.connection')));
        $this->onQueue(Json::nullableString(config('linear.queue.name')));
    }

    public function handle(LinearIssueSync $sync): void
    {
        $delivery = LinearCommentDelivery::query()->find($this->linearCommentDeliveryId);

        // Only pending work is sent; see CreateLinearIssue::handle().
        if ($delivery === null || $delivery->status !== LinearSyncStatus::Pending) {
            return;
        }

        try {
            $sync->pushComment($delivery);
        } catch (Throwable $e) {
            $final = ! CreateLinearIssue::isRetryable($e) || $this->attempts() >= $this->tries;

            $sync->recordCommentFailure($delivery, CreateLinearIssue::describe($e), $final);

            if (! $final) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 1800);
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        $delivery = LinearCommentDelivery::query()->find($this->linearCommentDeliveryId);

        if ($delivery !== null && $delivery->status !== LinearSyncStatus::Synced) {
            app(LinearIssueSync::class)->recordCommentFailure($delivery, CreateLinearIssue::describe($exception), true);
        }
    }
}
