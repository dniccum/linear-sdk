<?php

declare(strict_types=1);

namespace Dniccum\Linear\Events;

use Dniccum\Linear\Models\LinearIssueLink;

/**
 * Filing the issue failed for good (a permanent error, or the retries were
 * exhausted). The link is marked failed and can be retried manually.
 */
final readonly class LinearIssueFailed
{
    public function __construct(
        public LinearIssueLink $link,
        public string $message,
    ) {}
}
