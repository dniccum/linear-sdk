<?php

declare(strict_types=1);

namespace Dniccum\Linear\Observers;

use Dniccum\Linear\Services\LinearIssueSync;
use Dniccum\Linear\Support\ModelHooks;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Ties Linear to the Eloquent lifecycle of models using `CreatesLinearIssues`.
 *
 * A Linear problem must never break the host application's own save, so
 * every failure is reported and swallowed.
 */
final readonly class LinearModelObserver
{
    public function created(Model $model): void
    {
        $this->handle($model, 'created');
    }

    public function updated(Model $model): void
    {
        $this->handle($model, 'updated');
    }

    public function deleted(Model $model): void
    {
        $this->handle($model, 'deleted');
    }

    private function handle(Model $model, string $event): void
    {
        try {
            if (in_array($event, ModelHooks::events($model), true)) {
                app(LinearIssueSync::class)->handleEvent($model, $event);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
