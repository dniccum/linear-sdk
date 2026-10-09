<?php

declare(strict_types=1);

namespace Dniccum\Linear\Events;

use Dniccum\Linear\Contracts\CommentDelivery;

/**
 * A comment was posted to (or adopted on) the linked Linear issue. In Laravel `$delivery` is the
 * `LinearCommentDelivery` model.
 */
final readonly class LinearCommentDelivered
{
    public function __construct(
        public CommentDelivery $delivery,
    ) {}
}
