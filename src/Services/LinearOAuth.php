<?php

declare(strict_types=1);

namespace Dniccum\Linear\Services;

use Dniccum\Linear\Data\Tokens;
use Dniccum\Linear\Exceptions\LinearApiException;
use Dniccum\Linear\LinearConfig;
use Dniccum\Linear\Support\Json;
use Dniccum\Linear\Transport\Response;
use Dniccum\Linear\Transport\Transport;
use LogicException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Linear's OAuth 2.0 authorization-code flow (with PKCE), token refresh and
 * revocation.
 */
class LinearOAuth
{
    protected LoggerInterface $logger;

    public function __construct(
        protected Transport $http,
        protected LinearConfig $config,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger;
    }

    public function isConfigured(): bool
    {
        return $this->config->hasOAuthCredentials();
    }

    /**
     * The callback URL registered with the Linear application.
     *
     * @throws LogicException When no redirect URI is configured.
     */
    public function redirectUri(): string
    {
        return $this->config->redirectUri
            ?? throw new LogicException('No OAuth redirect URI is configured. Set the "redirect" option to the callback URL registered with your Linear application.');
    }

    /**
     * The scopes every grant must include.
     *
     * @return list<string>
     */
    public function scopes(): array
    {
        return $this->config->scopes;
    }

    /**
     * Build the URL that sends the user to Linear to approve access.
     *
     * prompt=consent lets a user who belongs to several workspaces pick which
     * one to connect, rather than silently reusing the last one.
     */
    public function authorizationUrl(string $state, string $codeVerifier): string
    {
        return $this->config->authorizeUrl.'?'.http_build_query([
            'client_id' => $this->config->clientId,
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => implode(',', $this->scopes()),
            'state' => $state,
            'code_challenge' => self::codeChallenge($codeVerifier),
            'code_challenge_method' => 'S256',
            'prompt' => 'consent',
        ]);
    }

    /**
     * S256 PKCE challenge: base64url(sha256(verifier)) without padding.
     */
    public static function codeChallenge(string $codeVerifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    }

    /**
     * Exchange the authorization code returned to the callback for tokens.
     *
     * @throws LinearApiException
     */
    public function exchangeCode(string $code, string $codeVerifier): Tokens
    {
        return $this->requestToken([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
            'code_verifier' => $codeVerifier,
        ]);
    }

    /**
     * @throws LinearApiException
     */
    public function refresh(string $refreshToken): Tokens
    {
        return $this->requestToken([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
    }

    /**
     * Revoke a token at Linear. Best effort: disconnecting locally must
     * succeed even when Linear is unreachable or the token is already dead.
     */
    public function revoke(string $token): bool
    {
        try {
            return $this->http
                ->postForm($this->config->apiEndpoint('/oauth/revoke'), ['Authorization' => 'Bearer '.$token], ['token' => $token])
                ->successful();
        } catch (Throwable $e) {
            $this->logger->warning('Failed to revoke a Linear token.', ['exception' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * @param  array<string, string>  $params
     *
     * @throws LinearApiException
     */
    protected function requestToken(array $params): Tokens
    {
        try {
            $response = $this->http->postForm($this->config->apiEndpoint('/oauth/token'), [], [
                ...$params,
                'client_id' => $this->config->clientId,
                'client_secret' => $this->config->clientSecret,
            ]);
        } catch (Throwable) {
            throw new LinearApiException('Could not reach Linear. Please try again.', LinearApiException::TRANSIENT);
        }

        $token = $response->json('access_token');

        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw $this->tokenFailure($response);
        }

        return Tokens::fromResponse(Json::map($response->json()));
    }

    protected function tokenFailure(Response $response): LinearApiException
    {
        $this->logger->warning('Linear rejected an OAuth token request.', [
            'status' => $response->status(),
            'error' => $response->json('error'),
        ]);

        if ($response->serverError()) {
            return new LinearApiException('Linear is temporarily unavailable. Please try again.', LinearApiException::TRANSIENT);
        }

        return new LinearApiException(
            'Linear did not accept the authorization. Please reconnect your Linear workspace.',
            LinearApiException::AUTHENTICATION,
        );
    }
}
