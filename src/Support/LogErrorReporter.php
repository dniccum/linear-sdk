<?php

declare(strict_types=1);

namespace Dniccum\Linear\Support;

use Dniccum\Linear\Contracts\ErrorReporter;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Reports exceptions to a PSR-3 logger.
 */
final readonly class LogErrorReporter implements ErrorReporter
{
    public function __construct(
        private LoggerInterface $logger = new NullLogger,
    ) {}

    public function report(Throwable $e): void
    {
        $this->logger->error($e->getMessage(), ['exception' => $e]);
    }
}
