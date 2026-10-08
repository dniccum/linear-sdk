<?php

declare(strict_types=1);

namespace Dniccum\Linear\Enums;

/**
 * The `type` of a Linear workflow state: the category every team's custom
 * statuses ("In Review", "QA", ...) belong to.
 */
enum LinearStateType: string
{
    /** New issues waiting to be sorted. */
    case Triage = 'triage';

    /** Not planned yet. */
    case Backlog = 'backlog';

    /** Planned, not started (usually "Todo"). */
    case Unstarted = 'unstarted';

    /** In progress, including review. */
    case Started = 'started';

    /** Done. */
    case Completed = 'completed';

    /** Closed without being done. */
    case Canceled = 'canceled';

    /** Closed as a duplicate; drawn like a cancellation. */
    case Duplicate = 'duplicate';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
