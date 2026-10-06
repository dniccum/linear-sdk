<?php

declare(strict_types=1);

namespace Dniccum\Linear\Actions;

use Dniccum\Linear\Data\TeamOptions;
use Dniccum\Linear\Exceptions\LinearApiException;
use Dniccum\Linear\Services\LinearClient;
use Dniccum\Linear\Support\ModelHooks;
use Illuminate\Database\Eloquent\Model;

/**
 * The projects, statuses, labels and members of one team.
 */
class ListTeamOptions
{
    public function __construct(
        private readonly LinearClient $client,
    ) {}

    /**
     * @throws LinearApiException
     */
    public function execute(Model $owner, string $teamId): TeamOptions
    {
        return $this->client->teamOptions(ModelHooks::connection($owner) ?? throw LinearApiException::notConnected(), $teamId);
    }
}
