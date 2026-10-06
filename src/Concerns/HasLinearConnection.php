<?php

declare(strict_types=1);

namespace Dniccum\Linear\Concerns;

use Dniccum\Linear\Actions\DisconnectLinear;
use Dniccum\Linear\Exceptions\LinearApiException;
use Dniccum\Linear\Models\LinearConnection;
use Dniccum\Linear\Models\LinearDestination;
use Dniccum\Linear\Services\ConnectionClient;
use Dniccum\Linear\Services\LinearClient;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Makes a model (a User, a Team, ...) able to connect a Linear workspace and
 * choose where its records are filed.
 *
 * Exposes a `linear_connected` attribute, which is safe to add to `$appends`.
 *
 * @phpstan-require-extends Model
 */
trait HasLinearConnection
{
    /**
     * @return MorphOne<LinearConnection, $this>
     */
    public function linearConnection(): MorphOne
    {
        return $this->morphOne(LinearConnection::class, 'owner');
    }

    /**
     * Where this owner's records are filed in Linear.
     *
     * @return MorphOne<LinearDestination, $this>
     */
    public function linearDestination(): MorphOne
    {
        return $this->morphOne(LinearDestination::class, 'owner');
    }

    /**
     * Whether a Linear workspace is connected, healthy or not. Uses the
     * loaded relation when there is one.
     */
    public function hasLinearConnection(): bool
    {
        return $this->relationLoaded('linearConnection')
            ? $this->getRelation('linearConnection') !== null
            : $this->linearConnection()->exists();
    }

    /**
     * @return Attribute<bool, never>
     */
    protected function linearConnected(): Attribute
    {
        return Attribute::get(fn (): bool => $this->hasLinearConnection());
    }

    /**
     * Revoke the credentials at Linear, delete the connection and fail any
     * queued work that was waiting on it.
     */
    public function disconnectLinear(): void
    {
        app(DisconnectLinear::class)->execute($this);
    }

    /**
     * A Linear API client bound to this owner's connection.
     *
     * @throws LinearApiException When Linear is not connected.
     */
    public function linearClient(): ConnectionClient
    {
        $connection = $this->linearConnection()->first() ?? throw LinearApiException::notConnected();

        return new ConnectionClient($connection, app(LinearClient::class));
    }
}
