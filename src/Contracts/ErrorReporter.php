<?php

declare(strict_types=1);

namespace Dniccum\Linear\Contracts;

use Throwable;

/**
 * Where unexpected exceptions go. The sync treats them as transient and
 * retries, but you should still hear about them.
 */
interface ErrorReporter
{
    public function report(Throwable $e): void;
}
