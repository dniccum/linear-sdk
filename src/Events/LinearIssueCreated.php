<?php

declare(strict_types=1);

namespace Dniccum\Linear\Events;

use Dniccum\Linear\Models\LinearIssueLink;

/**
 * A Linear issue now exists for the linked model: it was created, or an
 * earlier attempt's issue was adopted. `$link->linkable` is the model.
 */
final readonly class LinearIssueCreated
{
    public function __construct(
        public LinearIssueLink $link,
    ) {}
}
