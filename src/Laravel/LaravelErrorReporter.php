<?php

declare(strict_types=1);

namespace Dniccum\Linear\Laravel;

use Dniccum\Linear\Contracts\ErrorReporter;
use Throwable;

/**
 * Hands exceptions to Laravel's exception handler, as `report()` does.
 */
final class LaravelErrorReporter implements ErrorReporter
{
    public function report(Throwable $e): void
    {
        report($e);
    }
}
