<?php

declare(strict_types=1);

use Dniccum\Linear\Exceptions\LinearApiException;
use Dniccum\Linear\Services\LinearOAuth;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'linear.client_id' => 'linear-client',
        'linear.client_secret' => 'linear-secret',
        'linear.redirect' => 'https://app.test/linear/callback',
    ]);
});

test('it is configured only with client credentials', function () {
    expect(app(LinearOAuth::class)->isConfigured())->toBeTrue();

    config(['linear.client_secret' => null]);

    expect(app(LinearOAuth::class)->isConfigured())->toBeFalse();
});

test('the redirect URI falls back to the callback route', function () {
    expect(app(LinearOAuth::class)->redirectUri())->toBe('https://app.test/linear/callback');

    config(['linear.redirect' => null]);

    expect(app(LinearOAuth::class)->redirectUri())->toBe(route('linear.callback'))
        ->toEndWith('/linear/callback');
});

test('the authorization URL carries state, PKCE and the configured scopes', function () {
    $url = app(LinearOAuth::class)->authorizationUrl('state-1', 'verifier-1');

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($url)->toStartWith('https://linear.app/oauth/authorize?')
        ->and($query)->toMatchArray([
            'client_id' => 'linear-client',
            'redirect_uri' => 'https://app.test/linear/callback',
            'response_type' => 'code',
            'scope' => 'read,issues:create,comments:create',
            'state' => 'state-1',
            'code_challenge_method' => 'S256',
            'prompt' => 'consent',
            'code_challenge' => LinearOAuth::codeChallenge('verifier-1'),
        ]);
});

test('the PKCE challenge is the unpadded base64url SHA-256 of the verifier', function () {
    // RFC 7636 appendix B.
    expect(LinearOAuth::codeChallenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'))
        ->toBe('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM');
});

test('exchanging a code posts the verifier and client credentials', function () {
    fakeLinearApi();

    $tokens = app(LinearOAuth::class)->exchangeCode('auth-code', 'verifier');

    expect($tokens)->accessToken->toBe('lin_oauth_new')->refreshToken->toBe('lin_refresh_new')
        ->and($tokens->scopes)->toBe(['read', 'issues:create', 'comments:create']);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/oauth/token')
        && $request['grant_type'] === 'authorization_code'
        && $request['code'] === 'auth-code'
        && $request['code_verifier'] === 'verifier'
        && $request['redirect_uri'] === 'https://app.test/linear/callback'
        && $request['client_id'] === 'linear-client'
        && $request['client_secret'] === 'linear-secret');
});

test('refreshing posts the refresh token', function () {
    fakeLinearApi();

    app(LinearOAuth::class)->refresh('old-refresh');

    Http::assertSent(fn (Request $request) => $request['grant_type'] === 'refresh_token' && $request['refresh_token'] === 'old-refresh');
});

test('a token endpoint failure is classified', function (Closure $response, string $reason) {
    Http::fake(['api.linear.app/oauth/token' => $response]);

    try {
        app(LinearOAuth::class)->exchangeCode('c', 'v');
    } catch (LinearApiException $e) {
        expect($e->reason)->toBe($reason);

        return;
    }

    $this->fail('Expected a LinearApiException.');
})->with([
    'rejected grant' => [fn () => Http::response(['error' => 'invalid_grant'], 400), LinearApiException::AUTHENTICATION],
    'no access token' => [fn () => Http::response(['token_type' => 'Bearer'], 200), LinearApiException::AUTHENTICATION],
    'outage' => [fn () => Http::response('down', 503), LinearApiException::TRANSIENT],
    'network failure' => [fn () => throw new ConnectionException('timeout'), LinearApiException::TRANSIENT],
]);

test('revoking reports whether Linear accepted it', function () {
    Http::fake(['api.linear.app/oauth/revoke' => Http::sequence()->push([], 200)->push([], 400)]);

    $oauth = app(LinearOAuth::class);

    expect($oauth->revoke('token'))->toBeTrue()
        ->and($oauth->revoke('token'))->toBeFalse();

    Http::assertSent(fn (Request $request) => $request['token'] === 'token' && $request->hasHeader('Authorization', 'Bearer token'));
});

test('revoking never throws, even when Linear is unreachable', function () {
    Http::fake(['api.linear.app/oauth/revoke' => fn () => throw new ConnectionException('timeout')]);

    expect(app(LinearOAuth::class)->revoke('token'))->toBeFalse();
});
