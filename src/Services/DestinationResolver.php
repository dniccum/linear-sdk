<?php

declare(strict_types=1);

namespace Dniccum\Linear\Services;

use Dniccum\Linear\Data\Destination;
use Dniccum\Linear\Data\ResolvedDestination;
use Dniccum\Linear\Exceptions\LinearApiException;
use Dniccum\Linear\Models\LinearConnection;
use Illuminate\Validation\ValidationException;

/**
 * Checks a chosen Linear destination against what the connection can actually
 * see right now, so a stale or tampered team, project, status, label or
 * assignee is rejected before anything is saved or queued.
 *
 * Validation errors are keyed by the camelCase field names of the HTTP
 * contract (teamId, projectId, ...).
 */
class DestinationResolver
{
    public function __construct(
        protected LinearClient $client,
    ) {}

    /**
     * @throws ValidationException
     */
    public function resolve(LinearConnection $connection, Destination $destination): ResolvedDestination
    {
        try {
            $options = $this->client->teamOptions($connection, $destination->teamId);
        } catch (LinearApiException $e) {
            if ($e->reason !== LinearApiException::INVALID_REQUEST) {
                throw $e;
            }

            throw ValidationException::withMessages(['teamId' => $e->getMessage()]);
        }

        $errors = array_filter([
            'projectId' => $options->hasProject($destination->projectId) ? null : 'That project is not available in the selected team.',
            'stateId' => $options->hasState($destination->stateId) ? null : 'That status is not part of the selected team\'s workflow.',
            'assigneeId' => $options->hasMember($destination->assigneeId) ? null : 'That person is not an active member of the selected team.',
            'labelIds' => $options->hasLabels($destination->labelIds) ? null : 'One or more labels are not available in the selected team.',
        ]);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return new ResolvedDestination($destination, $options->team);
    }
}
