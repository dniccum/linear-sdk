<?php

declare(strict_types=1);

namespace Dniccum\Linear\Actions;

use Carbon\CarbonImmutable;
use Dniccum\Linear\Data\OAuthResult;
use Dniccum\Linear\Enums\LinearAuthMode;
use Dniccum\Linear\Enums\LinearConnectionStatus;
use Dniccum\Linear\Exceptions\LinearApiException;
use Dniccum\Linear\Models\LinearConnection;
use Dniccum\Linear\Services\LinearClient;
use Dniccum\Linear\Services\LinearOAuth;
use Dniccum\Linear\Support\Json;
use Dniccum\Linear\Support\ModelHooks;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Model;

/**
 * Complete the OAuth flow: verify state, exchange the code, check the grant
 * covers every required scope, and store the connection for the workspace the
 * user picked.
 *
 * Problems the user can fix by trying again come back as a failed
 * {@see OAuthResult} rather than as exceptions.
 */
class HandleOAuthCallback
{
    public function __construct(
        private readonly LinearOAuth $oauth,
        private readonly LinearClient $client,
    ) {}

    public function execute(Model $owner, Session $session, ?string $state, ?string $code, ?string $error = null): OAuthResult
    {
        $pending = Json::map($session->pull(BuildConnectUrl::SESSION_KEY));
        $expectedState = Json::string($pending['state'] ?? null);

        if ($expectedState === '' || $state === null || ! hash_equals($expectedState, $state)) {
            return OAuthResult::failed('We could not verify the Linear authorization. Please try connecting again.');
        }

        if (($error !== null && $error !== '') || $code === null || $code === '') {
            return OAuthResult::failed('Linear authorization was cancelled.');
        }

        try {
            $tokens = $this->oauth->exchangeCode($code, Json::string($pending['code_verifier'] ?? null));
            $viewer = $this->client->viewer($tokens->accessToken);
        } catch (LinearApiException $e) {
            return OAuthResult::failed($e->getMessage());
        }

        $missing = $tokens->missingScopes($this->oauth->scopes());

        // A response without scopes can't prove the grant is sufficient, so it
        // is treated as missing all of them.
        if ($missing !== []) {
            $this->oauth->revoke($tokens->accessToken);

            return OAuthResult::failed('Linear did not grant the permissions this app needs ('.implode(', ', $missing).'). Please approve all requested permissions.');
        }

        $previous = ModelHooks::connection($owner);

        $connection = LinearConnection::query()->updateOrCreate(
            ['owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->getKey()],
            [
                'auth_type' => LinearAuthMode::OAuth,
                'linear_organization_id' => $viewer->organization->id,
                'organization_name' => $viewer->organization->name,
                'organization_url_key' => $viewer->organization->urlKey,
                'linear_user_id' => $viewer->id,
                'linear_user_name' => $viewer->name,
                'linear_user_email' => $viewer->email,
                'access_token' => $tokens->accessToken,
                'refresh_token' => $tokens->refreshToken,
                'token_expires_at' => $tokens->expiresAt(CarbonImmutable::now()),
                'scopes' => $tokens->scopes,
                'status' => LinearConnectionStatus::Active,
                'last_error' => null,
                'last_error_at' => null,
            ],
        );

        if ($previous !== null && ! $previous->usesApiKey() && $previous->access_token !== $tokens->accessToken) {
            $this->oauth->revoke($previous->access_token);
        }

        $owner->unsetRelation('linearConnection');

        return OAuthResult::connected($connection);
    }
}
