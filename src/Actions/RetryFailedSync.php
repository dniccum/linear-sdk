<?php

declare(strict_types=1);

namespace Dniccum\Linear\Actions;

use Dniccum\Linear\Models\LinearIssueLink;
use Dniccum\Linear\Services\LinearIssueSync;
use Dniccum\Linear\Support\ModelHooks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * Requeue whatever failed to reach Linear for one of the owner's issue links:
 * the issue itself, or comments on it.
 */
class RetryFailedSync
{
    public function __construct(
        private readonly LinearIssueSync $sync,
    ) {}

    /**
     * @throws ModelNotFoundException When the link does not belong to the owner.
     * @throws ValidationException When the owner's connection needs reauthorizing first.
     */
    public function execute(Model $owner, int|string $linkId): void
    {
        $link = LinearIssueLink::query()->whereMorphedTo('owner', $owner)->findOrFail($linkId);

        if (ModelHooks::connection($owner)?->isActive() !== true) {
            throw ValidationException::withMessages(['linear' => 'Reconnect Linear before retrying.']);
        }

        $this->sync->retry($link);
    }
}
