<?php

declare(strict_types=1);

use Dniccum\Linear\Contracts\Mutex;
use Dniccum\Linear\Data\Destination;
use Dniccum\Linear\Data\IssuePayload;
use Dniccum\Linear\Enums\LinearAuthMode;
use Dniccum\Linear\Exceptions\LinearApiException;
use Dniccum\Linear\LinearConfig;
use Dniccum\Linear\Services\LinearClient;
use Dniccum\Linear\Services\LinearOAuth;
use Dniccum\Linear\Support\NullMutex;
use Dniccum\Linear\Testing\InMemory\InMemoryConnection;
use Dniccum\Linear\Tests\Core\Support\ScriptedHttp;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;

/*
|--------------------------------------------------------------------------
| The API client, with no framework
|--------------------------------------------------------------------------
|
| Illuminate's HTTP client runs standalone, so these tests talk to a plain
| `new Factory` with scripted answers. No application is booted.
|
*/

/**
 * The requests the scripted HTTP client received, in order.
 *
 * @return list<Request>
 */
function sentRequests(Factory $http): array
{
    return $http->recorded()->map(fn (array $pair): Request => $pair[0])->values()->all();
}

test('requests carry the credential in the form the auth mode needs', function () {
    $client = scriptedClient([ScriptedHttp::data(['viewer' => ['id' => 'u', 'name' => 'Ada', 'email' => 'ada@x.test', 'organization' => ['id' => 'o', 'name' => 'Acme', 'urlKey' => 'acme']]])], $http);

    $viewer = $client->viewer('lin_oauth');
    $client->viewer('lin_api_key', LinearAuthMode::ApiKey);
    [$oauth, $apiKey] = sentRequests($http);

    expect($viewer->organization->name)->toBe('Acme')
        ->and($oauth->url())->toBe('https://api.linear.app/graphql')
        ->and($oauth->header('Authorization'))->toBe(['Bearer lin_oauth'])
        ->and($oauth->isJson())->toBeTrue()
        ->and($oauth->data())->toHaveKey('query')->not->toHaveKey('variables')
        ->and($apiKey->header('Authorization'))->toBe(['lin_api_key']);
});

test('issues and comments are created with client generated ids and looked up by id', function () {
    $client = scriptedClient([
        ScriptedHttp::data(['issueCreate' => ['success' => true, 'issue' => ['id' => 'issue-1', 'identifier' => 'SUP-1', 'url' => 'https://linear.app/i/SUP-1']]]),
        ScriptedHttp::data(['issue' => ['id' => 'issue-1', 'identifier' => 'SUP-1', 'url' => 'https://linear.app/i/SUP-1']]),
        Factory::response(['data' => null, 'errors' => [['message' => 'Entity not found: Issue', 'extensions' => ['type' => 'invalid input']]]]),
        ScriptedHttp::data(['commentCreate' => ['success' => true, 'comment' => ['id' => 'c-1', 'url' => 'https://linear.app/c/1']]]),
        ScriptedHttp::data(['comment' => ['id' => 'c-1', 'url' => 'https://linear.app/c/1']]),
    ], $http);
    $connection = new InMemoryConnection(1);

    $issue = $client->createIssue($connection, 'issue-1', new IssuePayload(new Destination('team-1', priority: 2), 'Title', 'Body'));
    $found = $client->findIssue($connection, 'issue-1');
    $missing = $client->findIssue($connection, 'nope');
    $comment = $client->createComment($connection, 'c-1', 'issue-1', 'Hello');
    $foundComment = $client->findComment($connection, 'c-1');

    expect($issue->identifier)->toBe('SUP-1')
        ->and($found?->id)->toBe('issue-1')
        ->and($missing)->toBeNull()
        ->and($comment->id)->toBe('c-1')
        ->and($foundComment?->id)->toBe('c-1')
        ->and(sentRequests($http)[0]->data()['variables']['input'])->toBe(['id' => 'issue-1', 'teamId' => 'team-1', 'title' => 'Title', 'description' => 'Body', 'priority' => 2]);
});

test('failures are classified so callers know whether to retry', function (Closure $answer, string $reason, bool $reconnect) {
    $client = scriptedClient([$answer()]);
    $connection = new InMemoryConnection(1);

    try {
        $client->teams($connection);
        $this->fail('Expected a LinearApiException.');
    } catch (LinearApiException $e) {
        expect($e->reason)->toBe($reason)
            ->and($e->requiresReconnect())->toBe($reconnect)
            ->and($connection->isActive())->toBe(! $reconnect);
    }
})->with([
    'network failure' => [fn () => new ConnectionException('connection reset'), LinearApiException::TRANSIENT, false],
    'rejected credentials' => [fn () => Factory::response(null, 401), LinearApiException::AUTHENTICATION, true],
    'authentication error' => [fn () => Factory::response(['errors' => [['message' => 'x', 'extensions' => ['code' => 'AUTHENTICATION_ERROR']]]]), LinearApiException::AUTHENTICATION, true],
    'forbidden' => [fn () => Factory::response(['errors' => [['message' => 'No', 'extensions' => ['code' => 'FORBIDDEN', 'userPresentableMessage' => 'Nope']]]]), LinearApiException::FORBIDDEN, false],
    'rate limited' => [fn () => Factory::response(null, 429), LinearApiException::RATE_LIMITED, false],
    'server error' => [fn () => Factory::response(null, 503), LinearApiException::TRANSIENT, false],
    'invalid input' => [fn () => Factory::response(['errors' => [['message' => 'Bad input']]]), LinearApiException::INVALID_REQUEST, false],
    'no data' => [fn () => Factory::response(['data' => 'nope']), LinearApiException::TRANSIENT, false],
]);

test('an expiring token is refreshed under the mutex, and only once', function () {
    $config = new LinearConfig(clientId: 'id', clientSecret: 'secret');
    $http = ScriptedHttp::make([
        Factory::response(['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 3600]),
        ScriptedHttp::data(['teams' => ['nodes' => [['id' => 't1', 'name' => 'Support', 'key' => 'SUP']], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]),
    ]);
    $locks = [];
    $mutex = new class($locks) implements Mutex
    {
        /** @param list<array{string, int, int}> $locks */
        public function __construct(public array &$locks) {}

        public function synchronized(string $key, int $ttlSeconds, int $waitSeconds, Closure $callback): mixed
        {
            $this->locks[] = [$key, $ttlSeconds, $waitSeconds];

            return $callback();
        }
    };
    $client = new LinearClient($http, new LinearOAuth($http, $config), $config, null, $mutex);
    $connection = new InMemoryConnection(1, token: 'old', refresh: 'old-refresh', expiresAt: new DateTimeImmutable('+1 minute'));

    $teams = $client->teams($connection);
    [$refresh, $teamsRequest] = sentRequests($http);

    expect($teams[0]->key)->toBe('SUP')
        ->and($locks)->toBe([['linear-connection-refresh:1', 30, 15]])
        ->and($connection->token)->toBe('new-access')
        ->and($connection->refresh)->toBe('new-refresh')
        ->and($refresh->url())->toBe('https://api.linear.app/oauth/token')
        ->and($refresh->isForm())->toBeTrue()
        ->and($refresh->data())->toMatchArray(['grant_type' => 'refresh_token', 'refresh_token' => 'old-refresh', 'client_id' => 'id'])
        ->and($teamsRequest->header('Authorization'))->toBe(['Bearer new-access']);
});

test('a connection that cannot refresh is flagged for reconnection', function () {
    $client = scriptedClient([Factory::response(['error' => 'invalid_grant'], 400)]);
    $noRefresh = new InMemoryConnection(1, expiresAt: new DateTimeImmutable('-1 minute'));
    $stale = new InMemoryConnection(2, refresh: 'dead', expiresAt: new DateTimeImmutable('-1 minute'));

    expect(fn () => $client->teams($noRefresh))->toThrow(LinearApiException::class, 'expired')
        ->and($noRefresh->isActive())->toBeFalse()
        ->and(fn () => $client->teams($stale))->toThrow(LinearApiException::class, 'did not accept')
        ->and($stale->isActive())->toBeFalse()
        ->and(fn () => $client->teams($stale))->toThrow(LinearApiException::class, 'reauthorized');
});

test('a token revoked early is refreshed and the request retried once', function () {
    $config = new LinearConfig(clientId: 'id', clientSecret: 'secret');
    $http = ScriptedHttp::make([
        Factory::response(null, 401),
        Factory::response(['access_token' => 'rotated']),
        ScriptedHttp::data(['teams' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]),
    ]);
    $client = new LinearClient($http, new LinearOAuth($http, $config), $config);
    $connection = new InMemoryConnection(1, token: 'revoked', refresh: 'refresh', expiresAt: new DateTimeImmutable('+1 day'));

    expect($client->teams($connection))->toBe([])
        ->and($connection->token)->toBe('rotated')
        ->and($connection->refresh)->toBe('refresh');
});

test('the OAuth helper builds the PKCE authorization URL and exchanges codes', function () {
    $config = new LinearConfig(clientId: 'id', clientSecret: 'secret', redirectUri: 'https://app.test/callback', scopes: ['read', 'issues:create']);
    $http = ScriptedHttp::make([
        Factory::response(['access_token' => 'at', 'refresh_token' => 'rt', 'expires_in' => 60, 'scope' => 'read,issues:create']),
        Factory::response(null, 500),
        new ConnectionException('down'),
    ]);
    $oauth = new LinearOAuth($http, $config);

    parse_str(parse_url($oauth->authorizationUrl('state-1', 'verifier'), PHP_URL_QUERY) ?: '', $query);
    $tokens = $oauth->exchangeCode('code-1', 'verifier');

    expect($oauth->isConfigured())->toBeTrue()
        ->and($oauth->scopes())->toBe(['read', 'issues:create'])
        ->and($query)->toMatchArray([
            'client_id' => 'id', 'redirect_uri' => 'https://app.test/callback', 'response_type' => 'code', 'scope' => 'read,issues:create',
            'state' => 'state-1', 'code_challenge' => LinearOAuth::codeChallenge('verifier'), 'code_challenge_method' => 'S256', 'prompt' => 'consent',
        ])
        ->and($tokens->accessToken)->toBe('at')
        ->and($tokens->expiresAt()?->isFuture())->toBeTrue()
        ->and(sentRequests($http)[0]->data())->toMatchArray(['grant_type' => 'authorization_code', 'code' => 'code-1', 'redirect_uri' => 'https://app.test/callback', 'code_verifier' => 'verifier'])
        ->and(fn () => $oauth->refresh('rt'))->toThrow(LinearApiException::class, 'temporarily unavailable')
        ->and(fn () => $oauth->refresh('rt'))->toThrow(LinearApiException::class, 'Could not reach')
        ->and($oauth->revoke('at'))->toBeFalse();
});

test('the OAuth helper needs its settings', function () {
    $oauth = new LinearOAuth(ScriptedHttp::make([Factory::response([])]), new LinearConfig);

    expect($oauth->isConfigured())->toBeFalse()
        ->and(fn () => $oauth->redirectUri())->toThrow(LogicException::class, 'redirect URI');
});

test('revoking reports whether Linear accepted the token', function () {
    $oauth = new LinearOAuth(ScriptedHttp::make([Factory::response([])]), new LinearConfig);

    expect($oauth->revoke('token'))->toBeTrue();
});

test('without a mutex a refresh simply runs', function () {
    expect((new NullMutex)->synchronized('key', 1, 1, fn () => 'ran'))->toBe('ran');
});
