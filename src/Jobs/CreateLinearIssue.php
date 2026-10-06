<?php

declare(strict_types=1);

namespace Dniccum\Linear\Jobs;

use Dniccum\Linear\Enums\LinearSyncStatus;
use Dniccum\Linear\Exceptions\LinearApiException;
use Dniccum\Linear\Models\LinearIssueLink;
use Dniccum\Linear\Services\LinearIssueSync;
use Dniccum\Linear\Support\Json;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * File a model's Linear issue.
 *
 * Retryable failures (rate limits, outages) release the job with a backoff;
 * permanent ones (revoked access, a deleted team) mark the link failed
 * straight away so the cause can be fixed and the issue retried.
 */
class CreateLinearIssue implements ShouldQueue
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
        public int $linearIssueLinkId,
    ) {
        $this->onConnection(Json::nullableString(config('linear.queue.connection')));
        $this->onQueue(Json::nullableString(config('linear.queue.name')));
    }

    public function handle(LinearIssueSync $sync): void
    {
        $link = LinearIssueLink::query()->find($this->linearIssueLinkId);

        // Only pending work is sent. A link marked failed (for example by a
        // disconnect) waits for an explicit retry, even if this job was
        // already queued.
        if ($link === null || $link->status !== LinearSyncStatus::Pending) {
            return;
        }

        try {
            $sync->pushIssue($link);
        } catch (Throwable $e) {
            $final = ! self::isRetryable($e) || $this->attempts() >= $this->tries;

            $sync->recordIssueFailure($link, self::describe($e), $final);

            if (! $final) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 1800);
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        $link = LinearIssueLink::query()->find($this->linearIssueLinkId);

        if ($link !== null && ! $link->isSynced()) {
            app(LinearIssueSync::class)->recordIssueFailure($link, self::describe($exception), true);
        }
    }

    /**
     * Unexpected exceptions are reported and treated as transient.
     */
    public static function isRetryable(?Throwable $e): bool
    {
        if ($e instanceof LinearApiException) {
            return $e->isRetryable();
        }

        if ($e !== null) {
            report($e);
        }

        return true;
    }

    public static function describe(?Throwable $e): string
    {
        return $e instanceof LinearApiException
            ? $e->getMessage()
            : 'Something went wrong while syncing with Linear. Try again shortly.';
    }
}
