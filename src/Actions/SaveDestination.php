<?php

declare(strict_types=1);

namespace Dniccum\Linear\Actions;

use Dniccum\Linear\Data\Destination;
use Dniccum\Linear\Enums\LinearSendMode;
use Dniccum\Linear\Exceptions\LinearApiException;
use Dniccum\Linear\Models\LinearDestination;
use Dniccum\Linear\Services\DestinationResolver;
use Dniccum\Linear\Support\ModelHooks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Save the owner's destination after confirming it exists in the connected
 * workspace right now.
 */
class SaveDestination
{
    public function __construct(
        private readonly DestinationResolver $resolver,
    ) {}

    /**
     * @throws ValidationException When the team, project, status, labels or assignee are not available.
     * @throws LinearApiException When Linear is not connected or could not be reached.
     */
    public function execute(Model $owner, Destination $destination, LinearSendMode $sendMode = LinearSendMode::Automatic): LinearDestination
    {
        $connection = ModelHooks::connection($owner) ?? throw LinearApiException::notConnected();
        $resolved = $this->resolver->resolve($connection, $destination);

        return LinearDestination::query()->updateOrCreate(
            ['owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->getKey()],
            [
                ...$resolved->destination->toArray(),
                'team_name' => $resolved->team->name,
                'linear_organization_id' => $connection->linear_organization_id,
                'send_mode' => $sendMode,
            ],
        );
    }
}
