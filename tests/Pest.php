<?php

declare(strict_types=1);

use Dniccum\Linear\Models\LinearConnection;
use Dniccum\Linear\Tests\IgnoredRoutesTestCase;
use Dniccum\Linear\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Http;
use Workbench\App\Models\User;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

// The framework-agnostic core is tested without Laravel booted.
require_once __DIR__.'/Core/Support/helpers.php';
pest()->extend(IgnoredRoutesTestCase::class)->in('IgnoredRoutes');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Fake Linear's OAuth and GraphQL endpoints.
 *
 * Every GraphQL call is posted to the same URL, so responses are keyed by the
 * operation name in the query ("Teams", "CreateIssue", ...). A value may be the
 * `data` payload, a full response built with Http::response(), or a closure
 * receiving the request that returns either.
 *
 *     fakeLinearApi([
 *         'CreateIssue' => ['issueCreate' => ['success' => true, 'issue' => [...]]],
 *     ]);
 *
 * @param  array<string, mixed>  $operations
 * @param  array<string, mixed>|null  $token
 */
function fakeLinearApi(array $operations = [], ?array $token = null): void
{
    config([
        'linear.client_id' => 'linear-client',
        'linear.client_secret' => 'linear-secret',
        'linear.api_url' => 'https://api.linear.app',
    ]);

    Http::fake([
        'api.linear.app/oauth/token' => Http::response($token ?? [
            'access_token' => 'lin_oauth_new',
            'refresh_token' => 'lin_refresh_new',
            'expires_in' => 86400,
            'scope' => 'read,issues:create,comments:create',
            'token_type' => 'Bearer',
        ]),
        'api.linear.app/oauth/revoke' => Http::response([], 200),
        'api.linear.app/graphql' => function (Request $request) use ($operations) {
            preg_match('/(?:query|mutation)\s+(\w+)/', (string) $request['query'], $matches);
            $operation = $matches[1] ?? '';

            if (! array_key_exists($operation, $operations)) {
                return Http::response(['errors' => [['message' => "Unexpected operation {$operation}"]]], 400);
            }

            $response = $operations[$operation];

            if ($response instanceof Closure) {
                $response = $response($request);
            }

            return is_array($response)
                ? Http::response(['data' => $response])
                : $response;
        },
    ]);
}

/**
 * The GraphQL operation names sent to Linear, in order.
 *
 * @return list<string>
 */
function linearOperationsSent(): array
{
    return Http::recorded()
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn (Request $request) => str_ends_with($request->url(), '/graphql'))
        ->map(function (Request $request): string {
            preg_match('/(?:query|mutation)\s+(\w+)/', (string) $request['query'], $matches);

            return $matches[1] ?? '';
        })
        ->values()
        ->all();
}

/**
 * Fake responses for the operations behind LinearClient::teamOptions(), to
 * spread into fakeLinearApi().
 *
 * @return array<string, array<string, mixed>>
 */
function linearTeamOptionsOperations(): array
{
    $page = fn (array $nodes): array => ['nodes' => $nodes, 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]];

    return [
        'TeamOptions' => ['team' => [
            'id' => 'team-1',
            'name' => 'Support',
            'key' => 'SUP',
            'color' => '#5e6ad2',
            'icon' => '🛟',
            'states' => ['nodes' => [
                ['id' => 'state-2', 'name' => 'Todo', 'type' => 'unstarted', 'color' => '#e2e2e2', 'position' => 2],
                ['id' => 'state-1', 'name' => 'Triage', 'type' => 'triage', 'color' => '#bec2c8', 'position' => 1],
            ]],
        ]],
        'TeamProjects' => ['team' => ['projects' => $page([
            ['id' => 'project-1', 'name' => 'Inbox', 'color' => '#4cb782', 'icon' => '📥', 'completedAt' => null, 'canceledAt' => null],
            ['id' => 'project-done', 'name' => 'Old', 'color' => '#999999', 'icon' => null, 'completedAt' => '2026-01-01T00:00:00Z', 'canceledAt' => null],
        ])]],
        'TeamMembers' => ['team' => ['members' => $page([
            ['id' => 'user-1', 'name' => 'Ada Lovelace', 'displayName' => 'ada', 'active' => true, 'avatarUrl' => 'https://public.linear.app/ada.png', 'initials' => 'AL', 'avatarBackgroundColor' => '#5e6ad2'],
            ['id' => 'user-gone', 'name' => 'Gone', 'displayName' => 'gone', 'active' => false, 'avatarUrl' => null, 'initials' => 'G', 'avatarBackgroundColor' => '#000000'],
        ])]],
        'TeamLabels' => ['issueLabels' => $page([
            ['id' => 'label-1', 'name' => 'Bug', 'color' => '#f00', 'isGroup' => false],
            ['id' => 'label-group', 'name' => 'Area', 'color' => '#0f0', 'isGroup' => true],
        ])],
    ];
}

/**
 * A successful "CreateIssue" payload echoing the client-generated ID.
 */
function linearIssueCreated(string $identifier = 'SUP-42'): Closure
{
    return fn (Request $request) => ['issueCreate' => [
        'success' => true,
        'issue' => [
            'id' => $request['variables']['input']['id'],
            'identifier' => $identifier,
            'url' => "https://linear.app/acme/issue/{$identifier}",
        ],
    ]];
}

/**
 * A successful "CreateComment" payload echoing the client-generated ID.
 */
function linearCommentCreated(): Closure
{
    return fn (Request $request) => ['commentCreate' => [
        'success' => true,
        'comment' => ['id' => $request['variables']['input']['id'], 'url' => 'https://linear.app/acme/comment/1'],
    ]];
}

/**
 * The payload of a "Viewer" query.
 *
 * @return array<string, mixed>
 */
function linearViewer(string $organizationId = 'org-1'): array
{
    return ['viewer' => [
        'id' => 'linear-user-1',
        'name' => 'Ada Lovelace',
        'email' => 'ada@acme.test',
        'organization' => ['id' => $organizationId, 'name' => 'Acme', 'urlKey' => 'acme'],
    ]];
}

/**
 * A user who has connected Linear (an OAuth connection unless overridden).
 *
 * @param  array<string, mixed>  $connection
 */
function connectedUser(array $connection = []): User
{
    $user = User::factory()->create();

    LinearConnection::factory()->for($user, 'owner')->create($connection);

    return $user;
}

/**
 * Register the package routes again after the configuration or
 * Linear::ignoreRoutes() changed, as a fresh boot would.
 */
function reloadLinearRoutes(): void
{
    $router = app('router');
    $router->setRoutes(new RouteCollection);

    require dirname(__DIR__).'/routes/web.php';

    $router->getRoutes()->refreshNameLookups();
    $router->getRoutes()->refreshActionLookups();
}
