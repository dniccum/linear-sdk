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
use Dniccum\Linear\Tests\Core\Support\ScriptedTransport;
use Dniccum\Linear\Transport\Response;

/*
|--------------------------------------------------------------------------
| The API client, with no framework
|--------------------------------------------------------------------------
*/

test('requests carry the credential in the form the auth mode needs', function () {
    $client = scriptedClient([ScriptedTransport::data(['viewer' => ['id' => 'u', 'name' => 'Ada', 'email' => 'ada@x.test', 'organization' => ['id' => 'o', 'name' => 'Acme', 'urlKey' => 'acme']]])], $transport);

    $viewer = $client->viewer('lin_oauth');
    $client->viewer('lin_api_key', LinearAuthMode::ApiKey);

    expect($viewer->organization->name)->toBe('Acme')
        ->and($transport->requests[0]['url'])->toBe('https://api.linear.app/graphql')
        ->and($transport->requests[0]['headers'])->toBe(['Authorization' => 'Bearer lin_oauth'])
        ->and($transport->requests[0]['form'])->toBeFalse()
        ->and($transport->requests[1]['headers'])->toBe(['Authorization' => 'lin_api_key'])
        ->and($transport->requests[0]['body'])->toHaveKey('query')->not->toHaveKey('variables');
});

test('issues and comments are created with client generated ids and looked up by id', function () {
    $client = scriptedClient([
        ScriptedTransport::data(['issueCreate' => ['success' => true, 'issue' => ['id' => 'issue-1', 'identifier' => 'SUP-1', 'url' => 'https://linear.app/i/SUP-1']]]),
        ScriptedTransport::data(['issue' => ['id' => 'issue-1', 'identifier' => 'SUP-1', 'url' => 'https://linear.app/i/SUP-1']]),
        new Response(200, ['data' => null, 'errors' => [['message' => 'Entity not found: Issue', 'extensions' => ['type' => 'invalid input']]]]),
        ScriptedTransport::data(['commentCreate' => ['success' => true, 'comment' => ['id' => 'c-1', 'url' => 'https://linear.app/c/1']]]),
        ScriptedTransport::data(['comment' => ['id' => 'c-1', 'url' => 'https://linear.app/c/1']]),
    ], $transport);
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
        ->and($transport->requests[0]['body']['variables']['input'])->toBe(['id' => 'issue-1', 'teamId' => 'team-1', 'title' => 'Title', 'description' => 'Body', 'priority' => 2]);
});

test('failures are classified so callers know whether to retry', function (Response|Throwable $answer, string $reason, bool $reconnect) {
    $client = scriptedClient([$answer], $transport);
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
    'network failure' => [fn () => new RuntimeException('connection reset'), LinearApiException::TRANSIENT, false],
    'rejected credentials' => [new Response(401, null), LinearApiException::AUTHENTICATION, true],
    'authentication error' => [new Response(200, ['errors' => [['message' => 'x', 'extensions' => ['code' => 'AUTHENTICATION_ERROR']]]]), LinearApiException::AUTHENTICATION, true],
    'forbidden' => [new Response(200, ['errors' => [['message' => 'No', 'extensions' => ['code' => 'FORBIDDEN', 'userPresentableMessage' => 'Nope']]]]), LinearApiException::FORBIDDEN, false],
    'rate limited' => [new Response(429, null), LinearApiException::RATE_LIMITED, false],
    'server error' => [new Response(503, null), LinearApiException::TRANSIENT, false],
    'invalid input' => [new Response(200, ['errors' => [['message' => 'Bad input']]]), LinearApiException::INVALID_REQUEST, false],
    'no data' => [new Response(200, ['data' => 'nope']), LinearApiException::TRANSIENT, false],
]);

test('an expiring token is refreshed under the mutex, and only once', function () {
    $config = new LinearConfig(clientId: 'id', clientSecret: 'secret');
    $transport = new ScriptedTransport([
        new Response(200, ['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 3600]),
        ScriptedTransport::data(['teams' => ['nodes' => [['id' => 't1', 'name' => 'Support', 'key' => 'SUP']], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]),
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
    $client = new LinearClient($transport, new LinearOAuth($transport, $config), $config, null, $mutex);
    $connection = new InMemoryConnection(1, token: 'old', refresh: 'old-refresh', expiresAt: new DateTimeImmutable('+1 minute'));

    $teams = $client->teams($connection);

    expect($teams[0]->key)->toBe('SUP')
        ->and($locks)->toBe([['linear-connection-refresh:1', 30, 15]])
        ->and($connection->token)->toBe('new-access')
        ->and($connection->refresh)->toBe('new-refresh')
        ->and($transport->requests[0]['url'])->toBe('https://api.linear.app/oauth/token')
        ->and($transport->requests[0]['form'])->toBeTrue()
        ->and($transport->requests[0]['body'])->toMatchArray(['grant_type' => 'refresh_token', 'refresh_token' => 'old-refresh', 'client_id' => 'id'])
        ->and($transport->requests[1]['headers'])->toBe(['Authorization' => 'Bearer new-access']);
});

test('a connection that cannot refresh is flagged for reconnection', function () {
    $client = scriptedClient([new Response(400, ['error' => 'invalid_grant'])], $transport);
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
    $transport = new ScriptedTransport([
        new Response(401, null),
        new Response(200, ['access_token' => 'rotated']),
        ScriptedTransport::data(['teams' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]),
    ]);
    $client = new LinearClient($transport, new LinearOAuth($transport, $config), $config);
    $connection = new InMemoryConnection(1, token: 'revoked', refresh: 'refresh', expiresAt: new DateTimeImmutable('+1 day'));

    expect($client->teams($connection))->toBe([])
        ->and($connection->token)->toBe('rotated')
        ->and($connection->refresh)->toBe('refresh');
});

test('the OAuth helper builds the PKCE authorization URL and exchanges codes', function () {
    $config = new LinearConfig(clientId: 'id', clientSecret: 'secret', redirectUri: 'https://app.test/callback', scopes: ['read', 'issues:create']);
    $transport = new ScriptedTransport([
        new Response(200, ['access_token' => 'at', 'refresh_token' => 'rt', 'expires_in' => 60, 'scope' => 'read,issues:create']),
        new Response(500, null),
        new RuntimeException('down'),
    ]);
    $oauth = new LinearOAuth($transport, $config);

    parse_str(parse_url($oauth->authorizationUrl('state-1', 'verifier'), PHP_URL_QUERY) ?: '', $query);
    $tokens = $oauth->exchangeCode('code-1', 'verifier');

    expect($oauth->isConfigured())->toBeTrue()
        ->and($oauth->scopes())->toBe(['read', 'issues:create'])
        ->and($query)->toMatchArray([
            'client_id' => 'id', 'redirect_uri' => 'https://app.test/callback', 'response_type' => 'code', 'scope' => 'read,issues:create',
            'state' => 'state-1', 'code_challenge' => LinearOAuth::codeChallenge('verifier'), 'code_challenge_method' => 'S256', 'prompt' => 'consent',
        ])
        ->and($tokens->accessToken)->toBe('at')
        ->and($transport->requests[0]['body'])->toMatchArray(['grant_type' => 'authorization_code', 'code' => 'code-1', 'redirect_uri' => 'https://app.test/callback', 'code_verifier' => 'verifier'])
        ->and(fn () => $oauth->refresh('rt'))->toThrow(LinearApiException::class, 'temporarily unavailable')
        ->and(fn () => $oauth->refresh('rt'))->toThrow(LinearApiException::class, 'Could not reach')
        ->and($oauth->revoke('at'))->toBeFalse();
});

test('the OAuth helper needs its settings', function () {
    $oauth = new LinearOAuth(new ScriptedTransport, new LinearConfig);

    expect($oauth->isConfigured())->toBeFalse()
        ->and(fn () => $oauth->redirectUri())->toThrow(LogicException::class, 'redirect URI');
});

test('revoking reports whether Linear accepted the token', function () {
    $oauth = new LinearOAuth(new ScriptedTransport([new Response(200, [])]), new LinearConfig);

    expect($oauth->revoke('token'))->toBeTrue();
});

test('without a mutex a refresh simply runs', function () {
    expect((new NullMutex)->synchronized('key', 1, 1, fn () => 'ran'))->toBe('ran');
});
