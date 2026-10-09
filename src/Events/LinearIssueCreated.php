<?php

declare(strict_types=1);

namespace Dniccum\Linear\Events;

use Dniccum\Linear\Contracts\IssueLink;

/**
 * A Linear issue now exists for the linked model: it was created, or an
 * earlier attempt's issue was adopted. In Laravel `$link` is the
 * `LinearIssueLink` model and `$link->linkable` the record.
 */
final readonly class LinearIssueCreated
{
    public function __construct(
        public IssueLink $link,
    ) {}
}
