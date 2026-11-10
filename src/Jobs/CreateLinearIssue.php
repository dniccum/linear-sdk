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
 * File a model's Linear issue.
 *
 * Retryable failures (rate limits, outages) release the job with a backoff;
 * permanent ones (revoked access, a deleted team) mark the link failed
 * straight away so the cause can be fixed and the issue retried. The work
 * itself is {@see LinearIssueSync::processIssue()}.
 */
class CreateLinearIssue implements ShouldQueue
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
        public int $linearIssueLinkId,
    ) {
        $this->onConnection(Json::nullableString(config('linear.queue.connection')));
        $this->onQueue(Json::nullableString(config('linear.queue.name')));
    }

    public function handle(LinearIssueSync $sync): void
    {
        $delay = $sync->processIssue($this->linearIssueLinkId, $this->attempts(), $this->tries);

        if ($delay !== null) {
            $this->release($this->backoff[$this->attempts() - 1] ?? $delay);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(LinearIssueSync::class)->failIssue($this->linearIssueLinkId, $exception);
    }

    /**
     * Unexpected exceptions are reported and treated as transient.
     */
    public static function isRetryable(?Throwable $e): bool
    {
        return app(LinearIssueSync::class)->isRetryable($e);
    }

    public static function describe(?Throwable $e): string
    {
        return LinearIssueSync::describe($e);
    }
}
