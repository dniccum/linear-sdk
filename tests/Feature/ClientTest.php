<?php

declare(strict_types=1);

use Dniccum\Linear\Data\Destination;
use Dniccum\Linear\Data\IssuePayload;
use Dniccum\Linear\Data\Member;
use Dniccum\Linear\Data\Project;
use Dniccum\Linear\Data\Team;
use Dniccum\Linear\Data\TeamOptions;
use Dniccum\Linear\Data\WorkflowState;
use Dniccum\Linear\Enums\LinearAuthMode;
use Dniccum\Linear\Enums\LinearConnectionStatus;
use Dniccum\Linear\Exceptions\LinearApiException;
use Dniccum\Linear\Models\LinearConnection;
use Dniccum\Linear\Services\LinearClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Workbench\App\Models\User;

function linearConnection(array $attributes = []): LinearConnection
{
    return LinearConnection::factory()->for(User::factory()->create(), 'owner')->create($attributes);
}

test('it lists teams sorted by name using the connection token', function () {
    fakeLinearApi([
        'Teams' => ['teams' => ['nodes' => [
            ['id' => 'team-2', 'name' => 'engineering', 'key' => 'ENG'],
            ['id' => 'team-1', 'name' => 'Customer Success', 'key' => 'CS'],
        ]]],
    ]);

    $teams = app(LinearClient::class)->teams(linearConnection(['access_token' => 'secret-token']));

    expect($teams)->each->toBeInstanceOf(Team::class)
        ->and(array_column($teams, 'id'))->toBe(['team-1', 'team-2']);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/graphql')
        && $request->hasHeader('Authorization', 'Bearer secret-token'));
});

test('team options exclude closed projects, group labels and inactive members', function () {
    fakeLinearApi([...linearTeamOptionsOperations()]);

    $options = app(LinearClient::class)->teamOptions(linearConnection(), 'team-1');

    expect($options)->toBeInstanceOf(TeamOptions::class)
        ->and($options->team->name)->toBe('Support')
        ->and($options->team->color)->toBe('#5e6ad2')
        ->and($options->team->icon)->toBe('🛟')
        ->and($options->projects)->toEqual([new Project('project-1', 'Inbox', '#4cb782', '📥')])
        ->and($options->states)->toEqual([
            new WorkflowState('state-1', 'Triage', 'triage', '#bec2c8'),
            new WorkflowState('state-2', 'Todo', 'unstarted', '#e2e2e2'),
        ])
        ->and(array_column($options->states, 'id'))->toBe(['state-1', 'state-2'])
        ->and(array_column($options->labels, 'id'))->toBe(['label-1'])
        ->and($options->members)->toEqual([new Member('user-1', 'ada', 'https://public.linear.app/ada.png', 'AL', '#5e6ad2')]);
});

test('team options request the visual fields and cope with payloads that omit them', function () {
    $page = fn (array $nodes): array => ['nodes' => $nodes, 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]];

    fakeLinearApi([
        ...linearTeamOptionsOperations(),
        'TeamOptions' => ['team' => ['id' => 'team-1', 'name' => 'Support', 'key' => 'SUP', 'color' => null, 'states' => ['nodes' => [['id' => 's', 'name' => 'Open', 'type' => 'started']]]]],
        'TeamProjects' => ['team' => ['projects' => $page([['id' => 'p', 'name' => 'Bare']])]],
        'TeamMembers' => ['team' => ['members' => $page([
            ['id' => 'u-1', 'name' => 'Zed', 'displayName' => 'zed', 'avatarUrl' => '', 'initials' => 'Z'],
            ['id' => 'u-2', 'name' => 'Amy', 'displayName' => null, 'avatarUrl' => null],
        ])]],
    ]);

    $options = app(LinearClient::class)->teamOptions(linearConnection(), 'team-1');

    expect($options->team)->toEqual(new Team('team-1', 'Support', 'SUP'))
        ->and($options->states)->toEqual([new WorkflowState('s', 'Open', 'started')])
        ->and($options->projects)->toEqual([new Project('p', 'Bare')])
        ->and($options->members)->toEqual([new Member('u-2', 'Amy'), new Member('u-1', 'zed', null, 'Z')]);

    $queries = Http::recorded()->map(fn (array $pair): string => (string) $pair[0]['query'])->filter()->implode("\n");

    expect($queries)->toContain('id name key color icon', 'nodes { id name type color position }', 'nodes { id name color icon completedAt canceledAt }', 'avatarUrl initials avatarBackgroundColor');
});

test('team options fail with an invalid request when the team is not visible', function () {
    fakeLinearApi(['TeamOptions' => ['team' => null, 'issueLabels' => ['nodes' => []]]]);

    app(LinearClient::class)->teamOptions(linearConnection(), 'team-x');
})->throws(LinearApiException::class, 'no longer available');

test('an expiring token is refreshed before the request and the new tokens are stored', function () {
    fakeLinearApi(['Teams' => ['teams' => ['nodes' => []]]]);

    $connection = linearConnection([
        'access_token' => 'old-token',
        'refresh_token' => 'old-refresh',
        'token_expires_at' => now()->addMinute(),
    ]);

    app(LinearClient::class)->teams($connection);

    $connection->refresh();

    expect($connection->access_token)->toBe('lin_oauth_new')
        ->and($connection->refresh_token)->toBe('lin_refresh_new')
        ->and($connection->token_expires_at?->isFuture())->toBeTrue();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/oauth/token')
        && $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === 'old-refresh');
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/graphql')
        && $request->hasHeader('Authorization', 'Bearer lin_oauth_new'));
});

test('a refresh another worker already performed is not repeated', function () {
    fakeLinearApi(['Teams' => ['teams' => ['nodes' => []]]]);

    $connection = linearConnection([
        'access_token' => 'old-token',
        'token_expires_at' => now()->addMinute(),
    ]);

    // The other worker stored fresh tokens while this one held the stale row.
    LinearConnection::query()->findOrFail($connection->id)
        ->forceFill(['access_token' => 'rotated-token', 'token_expires_at' => now()->addDay()])
        ->save();

    app(LinearClient::class)->teams($connection);

    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/oauth/token'));
    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer rotated-token'));
});

test('an expiring token without a refresh token flags the connection for reconnection', function () {
    fakeLinearApi();

    $connection = linearConnection(['refresh_token' => null, 'token_expires_at' => now()->subMinute()]);

    expect(fn () => app(LinearClient::class)->teams($connection))
        ->toThrow(LinearApiException::class, 'has expired');

    expect($connection->fresh()->status)->toBe(LinearConnectionStatus::NeedsReconnect);
});

test('a refresh Linear rejects flags the connection for reconnection', function () {
    config(['linear.client_id' => 'linear-client', 'linear.client_secret' => 'linear-secret']);
    Http::fake(['api.linear.app/oauth/token' => Http::response(['error' => 'invalid_grant'], 400)]);

    $connection = linearConnection(['token_expires_at' => now()->subMinute()]);

    expect(fn () => app(LinearClient::class)->teams($connection))
        ->toThrow(fn (LinearApiException $e) => expect($e->requiresReconnect())->toBeTrue());

    expect($connection->fresh()->status)->toBe(LinearConnectionStatus::NeedsReconnect);
});

test('a rejected token is refreshed once and the request retried', function () {
    $calls = 0;

    fakeLinearApi([
        'Teams' => function () use (&$calls) {
            $calls++;

            return $calls === 1
                ? Http::response(['errors' => [['message' => 'Authentication required', 'extensions' => ['code' => 'AUTHENTICATION_ERROR']]]], 401)
                : ['teams' => ['nodes' => []]];
        },
    ]);

    $connection = linearConnection();

    expect(app(LinearClient::class)->teams($connection))->toBe([])
        ->and($calls)->toBe(2)
        ->and($connection->fresh()->status)->toBe(LinearConnectionStatus::Active);
});

test('a token that is still rejected after refreshing flags the connection for reconnection', function () {
    fakeLinearApi([
        'Teams' => Http::response(['errors' => [['message' => 'Authentication required', 'extensions' => ['code' => 'AUTHENTICATION_ERROR']]]], 401),
    ]);

    $connection = linearConnection();

    expect(fn () => app(LinearClient::class)->teams($connection))->toThrow(LinearApiException::class);

    expect($connection->fresh()->status)->toBe(LinearConnectionStatus::NeedsReconnect);
    Http::assertSentCount(3);
});

test('an authentication failure without a usable refresh flags the connection for reconnection', function () {
    fakeLinearApi([
        'Teams' => Http::response(['errors' => [['message' => 'Authentication required', 'extensions' => ['code' => 'AUTHENTICATION_ERROR']]]], 401),
    ]);

    $connection = linearConnection(['refresh_token' => null]);

    expect(fn () => app(LinearClient::class)->teams($connection))
        ->toThrow(fn (LinearApiException $e) => expect($e->requiresReconnect())->toBeTrue());

    expect($connection->fresh())
        ->status->toBe(LinearConnectionStatus::NeedsReconnect)
        ->last_error->toContain('Reconnect');
});

test('a connection flagged for reconnection is not used', function () {
    fakeLinearApi();

    $connection = LinearConnection::factory()->for(User::factory()->create(), 'owner')->needsReconnect()->create();

    expect(fn () => app(LinearClient::class)->teams($connection))->toThrow(LinearApiException::class);

    Http::assertNothingSent();
});

test('it classifies rate limits and outages as retryable', function (int $status, array $error, string $reason) {
    fakeLinearApi(['Teams' => Http::response(['errors' => [$error]], $status)]);

    try {
        app(LinearClient::class)->teams(linearConnection());
    } catch (LinearApiException $e) {
        expect($e->reason)->toBe($reason)
            ->and($e->isRetryable())->toBeTrue();

        return;
    }

    $this->fail('Expected a LinearApiException.');
})->with([
    'rate limited by code' => [400, ['message' => 'Too many', 'extensions' => ['code' => 'RATELIMITED']], LinearApiException::RATE_LIMITED],
    'rate limited by type' => [400, ['message' => 'Too many', 'extensions' => ['type' => 'ratelimited']], LinearApiException::RATE_LIMITED],
    'rate limited by status' => [429, ['message' => 'Too many'], LinearApiException::RATE_LIMITED],
    'server error' => [503, ['message' => 'Down'], LinearApiException::TRANSIENT],
    'internal error code' => [400, ['message' => 'Oops', 'extensions' => ['code' => 'INTERNAL_ERROR']], LinearApiException::TRANSIENT],
]);

test('insufficient permissions are reported without disconnecting', function () {
    fakeLinearApi(['Teams' => Http::response(['errors' => [[
        'message' => 'Forbidden',
        'extensions' => ['code' => 'FORBIDDEN', 'userPresentableMessage' => 'You need the issues:create scope.'],
    ]]], 400)]);

    $connection = linearConnection();

    expect(fn () => app(LinearClient::class)->teams($connection))
        ->toThrow(LinearApiException::class, 'issues:create');

    expect($connection->fresh())
        ->status->toBe(LinearConnectionStatus::Active)
        ->last_error->toContain('issues:create');
});

test('a forbidden response without a message gets a generic one', function () {
    fakeLinearApi(['Teams' => Http::response(['errors' => [['message' => '', 'extensions' => ['type' => 'forbidden']]]], 400)]);

    expect(fn () => app(LinearClient::class)->teams(linearConnection()))
        ->toThrow(LinearApiException::class, 'does not have permission');
});

test('an unclassified error is an invalid request carrying Linear\'s message', function () {
    fakeLinearApi(['Teams' => Http::response(['errors' => [['message' => 'Bad input']]], 400)]);

    try {
        app(LinearClient::class)->teams(linearConnection());
    } catch (LinearApiException $e) {
        expect($e->reason)->toBe(LinearApiException::INVALID_REQUEST)
            ->and($e->getMessage())->toBe('Bad input')
            ->and($e->isRetryable())->toBeFalse()
            ->and($e->requiresReconnect())->toBeFalse();

        return;
    }

    $this->fail('Expected a LinearApiException.');
});

test('an error without any message falls back to a generic one', function () {
    fakeLinearApi(['Teams' => Http::response(['errors' => [['extensions' => []]]], 400)]);

    expect(fn () => app(LinearClient::class)->teams(linearConnection()))
        ->toThrow(LinearApiException::class, 'Linear rejected the request.');
});

test('a failing status without a GraphQL error body is classified from the status', function () {
    fakeLinearApi(['Teams' => Http::response('nope', 502)]);

    expect(fn () => app(LinearClient::class)->teams(linearConnection()))
        ->toThrow(fn (LinearApiException $e) => expect($e->reason)->toBe(LinearApiException::TRANSIENT));
});

test('a response without data is treated as transient', function () {
    fakeLinearApi(['Teams' => Http::response(['unexpected' => true], 200)]);

    expect(fn () => app(LinearClient::class)->teams(linearConnection()))
        ->toThrow(LinearApiException::class, 'unexpected response');
});

test('a network failure is transient', function () {
    Http::fake(['api.linear.app/graphql' => fn () => throw new ConnectionException('timeout')]);

    expect(fn () => app(LinearClient::class)->teams(linearConnection()))
        ->toThrow(fn (LinearApiException $e) => expect($e->reason)->toBe(LinearApiException::TRANSIENT)->and($e->getMessage())->toContain('Could not reach Linear'));
});

test('finding a missing issue returns null', function () {
    fakeLinearApi(['FindIssue' => Http::response(['errors' => [[
        'message' => 'Entity not found: Issue',
        'extensions' => ['code' => 'INVALID_INPUT', 'userPresentableMessage' => 'Could not find referenced Issue.'],
    ]]], 400)]);

    expect(app(LinearClient::class)->findIssue(linearConnection(), 'missing'))->toBeNull();
});

test('finding an issue or comment that Linear returns as null yields null', function () {
    fakeLinearApi(['FindIssue' => ['issue' => null], 'FindComment' => ['comment' => null]]);

    $client = app(LinearClient::class);
    $connection = linearConnection();

    expect($client->findIssue($connection, 'x'))->toBeNull()
        ->and($client->findComment($connection, 'x'))->toBeNull();
});

test('finding an issue or comment returns what Linear has', function () {
    fakeLinearApi([
        'FindIssue' => ['issue' => ['id' => 'i-1', 'identifier' => 'SUP-1', 'url' => 'https://linear.app/acme/issue/SUP-1']],
        'FindComment' => ['comment' => ['id' => 'c-1', 'url' => 'https://linear.app/acme/comment/1']],
    ]);

    $client = app(LinearClient::class);
    $connection = linearConnection();

    expect($client->findIssue($connection, 'i-1'))->identifier->toBe('SUP-1')
        ->and($client->findComment($connection, 'c-1'))->url->toBe('https://linear.app/acme/comment/1');
});

test('a lookup that fails for another reason is not swallowed', function () {
    fakeLinearApi(['FindIssue' => Http::response(['errors' => [['message' => 'Bad input']]], 400)]);

    app(LinearClient::class)->findIssue(linearConnection(), 'x');
})->throws(LinearApiException::class, 'Bad input');

test('creating an issue sends the client generated id and the payload', function () {
    fakeLinearApi(['CreateIssue' => linearIssueCreated('SUP-3')]);

    $issue = app(LinearClient::class)->createIssue(
        linearConnection(),
        'issue-uuid',
        new IssuePayload(new Destination(teamId: 'team-1', priority: 2), 'Title', 'Body'),
    );

    expect($issue)->identifier->toBe('SUP-3')->id->toBe('issue-uuid');

    Http::assertSent(fn (Request $request) => ($request['variables']['input'] ?? null) === [
        'id' => 'issue-uuid', 'teamId' => 'team-1', 'title' => 'Title', 'description' => 'Body', 'priority' => 2,
    ]);
});

test('creating a comment sends the client generated id', function () {
    fakeLinearApi(['CreateComment' => linearCommentCreated()]);

    $comment = app(LinearClient::class)->createComment(linearConnection(), 'comment-uuid', 'issue-uuid', 'Hello');

    expect($comment->id)->toBe('comment-uuid');

    Http::assertSent(fn (Request $request) => ($request['variables']['input'] ?? null) === [
        'id' => 'comment-uuid', 'issueId' => 'issue-uuid', 'body' => 'Hello',
    ]);
});

test('a mutation Linear does not confirm is a transient failure', function () {
    fakeLinearApi([
        'CreateIssue' => ['issueCreate' => ['success' => false, 'issue' => null]],
        'CreateComment' => ['commentCreate' => null],
    ]);

    $client = app(LinearClient::class);
    $connection = linearConnection();

    expect(fn () => $client->createIssue($connection, 'i', new IssuePayload(new Destination('team-1'), 't', 'd')))
        ->toThrow(LinearApiException::class, 'did not create the Linear issue')
        ->and(fn () => $client->createComment($connection, 'c', 'i', 'b'))
        ->toThrow(LinearApiException::class, 'did not post the Linear comment');
});

test('team options follow pagination cursors', function () {
    $page = fn (array $nodes, ?string $next) => ['nodes' => $nodes, 'pageInfo' => ['hasNextPage' => $next !== null, 'endCursor' => $next]];

    fakeLinearApi([
        ...linearTeamOptionsOperations(),
        'TeamMembers' => fn (Request $request) => ['team' => ['members' => ($request['variables']['after'] ?? null) === null
            ? $page([['id' => 'user-1', 'name' => 'Ada', 'displayName' => '', 'active' => true]], 'cursor-2')
            : $page([['id' => 'user-251', 'name' => 'Zed', 'displayName' => '', 'active' => true]], null)]],
    ]);

    $options = app(LinearClient::class)->teamOptions(linearConnection(), 'team-1');

    expect(array_column($options->members, 'id'))->toBe(['user-1', 'user-251']);

    Http::assertSent(fn (Request $request) => ($request['variables']['after'] ?? null) === 'cursor-2');
});

test('pagination stops after twenty pages', function () {
    $pages = 0;

    fakeLinearApi([
        ...linearTeamOptionsOperations(),
        'TeamMembers' => function () use (&$pages) {
            $pages++;

            return ['team' => ['members' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => true, 'endCursor' => "cursor-{$pages}"]]]];
        },
    ]);

    app(LinearClient::class)->teamOptions(linearConnection(), 'team-1');

    expect($pages)->toBe(20);
});

test('pagination stops when Linear sends no cursor', function () {
    $pages = 0;

    fakeLinearApi([
        ...linearTeamOptionsOperations(),
        'TeamMembers' => function () use (&$pages) {
            $pages++;

            return ['team' => ['members' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => true, 'endCursor' => null]]]];
        },
    ]);

    app(LinearClient::class)->teamOptions(linearConnection(), 'team-1');

    expect($pages)->toBe(1);
});

test('team options declare each teamId variable with the type Linear expects at its position', function () {
    fakeLinearApi([...linearTeamOptionsOperations()]);

    app(LinearClient::class)->teamOptions(linearConnection(), 'team-1');

    // team(id:) takes a String!, but the issueLabels team filter compares
    // against an ID; declaring it String! there makes Linear reject the query.
    $declared = fn (string $operation) => Http::recorded(fn (Request $request) => str_contains($request['query'] ?? '', "query {$operation}("))
        ->map(fn (array $pair) => preg_match('/\$teamId:\s*([\w!]+)/', $pair[0]['query'], $match) === 1 ? $match[1] : null)
        ->unique()->values()->all();

    expect($declared('TeamLabels'))->toBe(['ID!'])
        ->and($declared('TeamOptions'))->toBe(['String!'])
        ->and($declared('TeamProjects'))->toBe(['String!'])
        ->and($declared('TeamMembers'))->toBe(['String!']);
});

test('an API key is sent as the raw Authorization header and never as a Bearer token', function () {
    fakeLinearApi(['Teams' => ['teams' => ['nodes' => []]]]);

    app(LinearClient::class)->teams(linearConnection(['auth_type' => LinearAuthMode::ApiKey, 'access_token' => 'lin_api_secret', 'refresh_token' => null, 'token_expires_at' => null]));

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'lin_api_secret'));
    Http::assertNotSent(fn (Request $request) => str_contains($request->header('Authorization')[0] ?? '', 'Bearer'));
});

test('an API key is never refreshed, even with a stale expiry', function () {
    fakeLinearApi(['Teams' => ['teams' => ['nodes' => []]]]);

    $connection = LinearConnection::factory()->for(User::factory()->create(), 'owner')->apiKey('lin_api_secret')->create(['token_expires_at' => now()->subDay()]);

    app(LinearClient::class)->teams($connection);

    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/oauth/token'));
});

test('a rejected API key flags the connection for reconnection without refreshing', function () {
    fakeLinearApi([
        'Teams' => Http::response(['errors' => [['message' => 'Authentication required', 'extensions' => ['code' => 'AUTHENTICATION_ERROR']]]], 401),
    ]);

    $connection = LinearConnection::factory()->for(User::factory()->create(), 'owner')->apiKey()->create();

    expect(fn () => app(LinearClient::class)->teams($connection))
        ->toThrow(fn (LinearApiException $e) => expect($e->requiresReconnect())->toBeTrue());

    expect($connection->fresh()->status)->toBe(LinearConnectionStatus::NeedsReconnect);
    Http::assertSentCount(1);
});

test('the viewer query validates a credential before a connection exists', function () {
    fakeLinearApi(['Viewer' => linearViewer()]);

    $viewer = app(LinearClient::class)->viewer('lin_api_secret', LinearAuthMode::ApiKey);

    expect($viewer->organization->name)->toBe('Acme')
        ->and($viewer->email)->toBe('ada@acme.test');

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'lin_api_secret'));

    app(LinearClient::class)->viewer('oauth-token');

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer oauth-token'));
});
