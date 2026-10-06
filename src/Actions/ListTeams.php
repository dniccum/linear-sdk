<?php

declare(strict_types=1);

namespace Dniccum\Linear\Actions;

use Dniccum\Linear\Data\Team;
use Dniccum\Linear\Exceptions\LinearApiException;
use Dniccum\Linear\Services\LinearClient;
use Dniccum\Linear\Support\ModelHooks;
use Illuminate\Database\Eloquent\Model;

/**
 * The teams the owner's connection can file issues in.
 */
class ListTeams
{
    public function __construct(
        private readonly LinearClient $client,
    ) {}

    /**
     * @return list<Team>
     *
     * @throws LinearApiException
     */
    public function execute(Model $owner): array
    {
        return $this->client->teams(ModelHooks::connection($owner) ?? throw LinearApiException::notConnected());
    }
}
