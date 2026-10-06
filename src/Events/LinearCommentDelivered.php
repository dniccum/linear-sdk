<?php

declare(strict_types=1);

namespace Dniccum\Linear\Events;

use Dniccum\Linear\Models\LinearCommentDelivery;

/**
 * A comment was posted to (or adopted on) the linked Linear issue.
 */
final readonly class LinearCommentDelivered
{
    public function __construct(
        public LinearCommentDelivery $delivery,
    ) {}
}
