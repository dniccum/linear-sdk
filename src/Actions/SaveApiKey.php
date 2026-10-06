<?php

declare(strict_types=1);

namespace Dniccum\Linear\Actions;

use Dniccum\Linear\Enums\LinearAuthMode;
use Dniccum\Linear\Enums\LinearConnectionStatus;
use Dniccum\Linear\Exceptions\LinearApiException;
use Dniccum\Linear\Models\LinearConnection;
use Dniccum\Linear\Services\LinearClient;
use Dniccum\Linear\Services\LinearOAuth;
use Dniccum\Linear\Support\ModelHooks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Connect a personal API key: it is validated against Linear (the viewer
 * query), then stored encrypted. Linear personal keys never expire, so there
 * is no refresh token.
 */
class SaveApiKey
{
    public function __construct(
        private readonly LinearClient $client,
        private readonly LinearOAuth $oauth,
    ) {}

    /**
     * @throws ValidationException When Linear rejects the key (keyed `apiKey`).
     * @throws LinearApiException When Linear could not be reached.
     */
    public function execute(Model $owner, string $apiKey): LinearConnection
    {
        $apiKey = trim($apiKey);

        try {
            $viewer = $this->client->viewer($apiKey, LinearAuthMode::ApiKey);
        } catch (LinearApiException $e) {
            if ($e->requiresReconnect()) {
                throw ValidationException::withMessages(['apiKey' => 'Linear did not accept that API key. Check it and try again.']);
            }

            throw $e;
        }

        $previous = ModelHooks::connection($owner);

        $connection = LinearConnection::query()->updateOrCreate(
            ['owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->getKey()],
            [
                'auth_type' => LinearAuthMode::ApiKey,
                'linear_organization_id' => $viewer->organization->id,
                'organization_name' => $viewer->organization->name,
                'organization_url_key' => $viewer->organization->urlKey,
                'linear_user_id' => $viewer->id,
                'linear_user_name' => $viewer->name,
                'linear_user_email' => $viewer->email,
                'access_token' => $apiKey,
                'refresh_token' => null,
                'token_expires_at' => null,
                'scopes' => null,
                'status' => LinearConnectionStatus::Active,
                'last_error' => null,
                'last_error_at' => null,
            ],
        );

        // Replacing an OAuth authorization: don't leave its token alive.
        if ($previous !== null && ! $previous->usesApiKey()) {
            $this->oauth->revoke($previous->access_token);
        }

        $owner->unsetRelation('linearConnection');

        return $connection;
    }
}
