<?php

declare(strict_types=1);

namespace Dniccum\Linear\Actions;

use Dniccum\Linear\Exceptions\LinearApiException;
use Dniccum\Linear\Services\LinearOAuth;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;

/**
 * Start the OAuth flow: bind the round trip to the session with a state value
 * and a PKCE verifier, and return the Linear authorization URL to send the
 * user to.
 */
class BuildConnectUrl
{
    public const string SESSION_KEY = 'linear_oauth';

    public function __construct(
        private readonly LinearOAuth $oauth,
    ) {}

    /**
     * @throws LinearApiException When the OAuth client credentials are not configured.
     */
    public function execute(Session $session): string
    {
        if (! $this->oauth->isConfigured()) {
            throw LinearApiException::notConfigured();
        }

        $state = Str::random(40);
        $codeVerifier = Str::random(96);

        $session->put(self::SESSION_KEY, ['state' => $state, 'code_verifier' => $codeVerifier]);

        return $this->oauth->authorizationUrl($state, $codeVerifier);
    }
}
