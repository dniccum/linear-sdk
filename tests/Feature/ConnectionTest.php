<?php

declare(strict_types=1);

use Dniccum\Linear\Enums\LinearAuthMode;
use Dniccum\Linear\Enums\LinearConnectionStatus;
use Dniccum\Linear\Enums\LinearSyncStatus;
use Dniccum\Linear\Jobs\CreateLinearIssue;
use Dniccum\Linear\Models\LinearConnection;
use Dniccum\Linear\Models\LinearIssueLink;
use Dniccum\Linear\Services\LinearIssueSync;
use Dniccum\Linear\Services\LinearOAuth;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Workbench\App\Models\Ticket;
use Workbench\App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('connecting redirects to Linear with state, PKCE and minimal scopes', function () {
    fakeLinearApi();

    $response = $this->actingAs($this->user)->get(route('linear.connect'));

    $response->assertRedirect();
    $location = $response->headers->get('Location');
    parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);
    $pending = session('linear_oauth');

    expect($location)->toStartWith('https://linear.app/oauth/authorize?')
        ->and($query['client_id'])->toBe('linear-client')
        ->and($query['redirect_uri'])->toBe(route('linear.callback'))
        ->and($query['scope'])->toBe('read,issues:create,comments:create')
        ->and($query['state'])->toBe($pending['state'])
        ->and($query['code_challenge_method'])->toBe('S256')
        ->and($query['code_challenge'])->toBe(LinearOAuth::codeChallenge($pending['code_verifier']));
});

test('connecting is unavailable when the Linear app is not configured', function () {
    config(['linear.client_id' => null]);

    $this->actingAs($this->user)->get(route('linear.connect'))->assertNotFound();
});

test('connecting is unavailable in API key mode', function () {
    fakeLinearApi();
    config(['linear.auth_mode' => 'api_key']);

    $this->actingAs($this->user)->get(route('linear.connect'))->assertNotFound();
    $this->actingAs($this->user)->get(route('linear.callback', ['code' => 'c', 'state' => 's']))->assertNotFound();
});

test('the callback stores an encrypted connection for the authorized workspace', function () {
    fakeLinearApi(['Viewer' => linearViewer()]);

    $this->actingAs($this->user)
        ->withSession(['linear_oauth' => ['state' => 'expected-state', 'code_verifier' => 'verifier']])
        ->get(route('linear.callback', ['code' => 'auth-code', 'state' => 'expected-state']))
        ->assertRedirect(route('linear.settings'))
        ->assertSessionHas('linear_status', 'Connected to Acme on Linear.');

    $connection = $this->user->fresh()->linearConnection;

    expect($connection)
        ->linear_organization_id->toBe('org-1')
        ->organization_name->toBe('Acme')
        ->linear_user_email->toBe('ada@acme.test')
        ->access_token->toBe('lin_oauth_new')
        ->refresh_token->toBe('lin_refresh_new')
        ->auth_type->toBe(LinearAuthMode::OAuth)
        ->status->toBe(LinearConnectionStatus::Active);

    $raw = DB::table('linear_connections')->first();
    expect($raw->access_token)->not->toContain('lin_oauth_new')
        ->and($raw->refresh_token)->not->toContain('lin_refresh_new');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/oauth/token')
        && $request['grant_type'] === 'authorization_code'
        && $request['code'] === 'auth-code'
        && $request['code_verifier'] === 'verifier');
});

test('the callback sends users to the configured settings URL', function () {
    fakeLinearApi(['Viewer' => linearViewer()]);
    config(['linear.settings_url' => 'https://app.test/account/linear']);

    $this->actingAs($this->user)
        ->withSession(['linear_oauth' => ['state' => 's', 'code_verifier' => 'v']])
        ->get(route('linear.callback', ['code' => 'c', 'state' => 's']))
        ->assertRedirect('https://app.test/account/linear');
});

test('the callback rejects a mismatched state', function () {
    fakeLinearApi(['Viewer' => linearViewer()]);

    $this->actingAs($this->user)
        ->withSession(['linear_oauth' => ['state' => 'expected-state', 'code_verifier' => 'verifier']])
        ->get(route('linear.callback', ['code' => 'auth-code', 'state' => 'forged']))
        ->assertRedirect(route('linear.settings'))
        ->assertSessionHas('linear_error');

    expect($this->user->fresh()->linearConnection)->toBeNull();
    Http::assertNothingSent();
});

test('the callback rejects a round trip that was never started', function () {
    fakeLinearApi();

    $this->actingAs($this->user)
        ->get(route('linear.callback', ['code' => 'auth-code', 'state' => 'anything']))
        ->assertSessionHas('linear_error');

    $this->actingAs($this->user)
        ->withSession(['linear_oauth' => ['state' => 's', 'code_verifier' => 'v']])
        ->get(route('linear.callback', ['code' => 'auth-code']))
        ->assertSessionHas('linear_error');
});

test('the callback handles a cancelled authorization', function () {
    fakeLinearApi();

    $this->actingAs($this->user)
        ->withSession(['linear_oauth' => ['state' => 's', 'code_verifier' => 'v']])
        ->get(route('linear.callback', ['error' => 'access_denied', 'state' => 's']))
        ->assertSessionHas('linear_error', 'Linear authorization was cancelled.');

    expect($this->user->fresh()->linearConnection)->toBeNull();
});

test('the callback reports a token exchange Linear rejects', function () {
    fakeLinearApi(token: ['error' => 'invalid_grant']);
    Http::fake();

    $this->actingAs($this->user)
        ->withSession(['linear_oauth' => ['state' => 's', 'code_verifier' => 'v']])
        ->get(route('linear.callback', ['code' => 'c', 'state' => 's']))
        ->assertSessionHas('linear_error');

    expect($this->user->fresh()->linearConnection)->toBeNull();
});

test('the callback refuses a grant missing required scopes', function () {
    fakeLinearApi(['Viewer' => linearViewer()], token: [
        'access_token' => 'narrow-token',
        'expires_in' => 3600,
        'scope' => 'read',
    ]);

    $this->actingAs($this->user)
        ->withSession(['linear_oauth' => ['state' => 's', 'code_verifier' => 'v']])
        ->get(route('linear.callback', ['code' => 'c', 'state' => 's']))
        ->assertSessionHas('linear_error', fn (string $message) => str_contains($message, 'issues:create'));

    expect($this->user->fresh()->linearConnection)->toBeNull();
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/oauth/revoke'));
});

test('the callback refuses a grant that does not report its scopes', function () {
    fakeLinearApi(['Viewer' => linearViewer()], token: [
        'access_token' => 'unscoped-token',
        'expires_in' => 3600,
    ]);

    $this->actingAs($this->user)
        ->withSession(['linear_oauth' => ['state' => 's', 'code_verifier' => 'v']])
        ->get(route('linear.callback', ['code' => 'c', 'state' => 's']))
        ->assertSessionHas('linear_error');

    expect($this->user->fresh()->linearConnection)->toBeNull();
});

test('reconnecting replaces the stored tokens and restores an unhealthy connection', function () {
    fakeLinearApi(['Viewer' => linearViewer()]);

    LinearConnection::factory()->for($this->user, 'owner')->needsReconnect()->create(['access_token' => 'stale']);

    $this->actingAs($this->user)
        ->withSession(['linear_oauth' => ['state' => 's', 'code_verifier' => 'v']])
        ->get(route('linear.callback', ['code' => 'c', 'state' => 's']));

    expect(LinearConnection::count())->toBe(1)
        ->and($this->user->fresh()->linearConnection)
        ->status->toBe(LinearConnectionStatus::Active)
        ->access_token->toBe('lin_oauth_new')
        ->last_error->toBeNull();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/oauth/revoke') && $request['token'] === 'stale');
});

test('reconnecting after an API key does not try to revoke the key', function () {
    fakeLinearApi(['Viewer' => linearViewer()]);

    LinearConnection::factory()->for($this->user, 'owner')->apiKey('lin_api_old')->create();

    $this->actingAs($this->user)
        ->withSession(['linear_oauth' => ['state' => 's', 'code_verifier' => 'v']])
        ->get(route('linear.callback', ['code' => 'c', 'state' => 's']));

    expect($this->user->fresh()->linearConnection->auth_type)->toBe(LinearAuthMode::OAuth);

    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/oauth/revoke'));
});

test('disconnecting revokes and deletes the credentials and fails queued work', function () {
    fakeLinearApi();

    $connection = LinearConnection::factory()->for($this->user, 'owner')->create(['access_token' => 'to-revoke']);
    $ticket = Ticket::factory()->for($this->user)->create();
    $link = LinearIssueLink::factory()->for($ticket, 'linkable')->for($this->user, 'owner')->create();

    $this->actingAs($this->user)
        ->delete(route('linear.disconnect'))
        ->assertRedirect(route('linear.settings'))
        ->assertSessionHas('linear_status', 'Linear has been disconnected.');

    expect(LinearConnection::find($connection->id))->toBeNull()
        ->and($link->fresh()->status)->toBe(LinearSyncStatus::Failed)
        ->and($link->fresh()->last_error)->toContain('disconnected');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/oauth/revoke') && $request['token'] === 'to-revoke');
});

test('disconnecting an API key does not call Linear', function () {
    fakeLinearApi();

    LinearConnection::factory()->for($this->user, 'owner')->apiKey()->create();

    $this->actingAs($this->user)->delete(route('linear.disconnect'))->assertRedirect(route('linear.settings'));

    expect($this->user->linearConnection()->exists())->toBeFalse();
    Http::assertNothingSent();
});

test('disconnecting without a connection says so', function () {
    $this->actingAs($this->user)
        ->delete(route('linear.disconnect'))
        ->assertSessionHas('linear_status', 'Linear is not connected.');
});

test('disconnecting can answer with JSON', function () {
    fakeLinearApi();
    LinearConnection::factory()->for($this->user, 'owner')->create();

    $this->actingAs($this->user)->deleteJson(route('linear.disconnect'))->assertOk()->assertExactJson(['connection' => null]);
});

test('work failed by a disconnect is not sent by an already queued job after reconnecting', function () {
    fakeLinearApi(['CreateIssue' => linearIssueCreated()]);

    LinearConnection::factory()->for($this->user, 'owner')->create();
    $ticket = Ticket::factory()->for($this->user)->create();
    $link = LinearIssueLink::factory()->for($ticket, 'linkable')->for($this->user, 'owner')->create();

    $this->actingAs($this->user)->delete(route('linear.disconnect'));
    LinearConnection::factory()->for($this->user, 'owner')->create();

    (new CreateLinearIssue($link->id))->handle(app(LinearIssueSync::class));

    expect($link->fresh()->status)->toBe(LinearSyncStatus::Failed)
        ->and(linearOperationsSent())->toBe([]);
});

test('connection tokens are hidden from serialization', function () {
    $connection = LinearConnection::factory()->for($this->user, 'owner')->create();

    expect($connection->toArray())->not->toHaveKeys(['access_token', 'refresh_token']);
});

test('team lookups require a connection', function () {
    fakeLinearApi();

    $this->actingAs($this->user)
        ->getJson(route('linear.api.teams'))
        ->assertStatus(409)
        ->assertJson(['reconnect' => true]);
});

test('team lookups return the connected workspace teams', function () {
    fakeLinearApi(['Teams' => ['teams' => ['nodes' => [['id' => 'team-1', 'name' => 'Support', 'key' => 'SUP']]]]]);

    LinearConnection::factory()->for($this->user, 'owner')->create();

    $this->actingAs($this->user)
        ->getJson(route('linear.api.teams'))
        ->assertOk()
        ->assertExactJson(['teams' => [['id' => 'team-1', 'name' => 'Support', 'key' => 'SUP']]]);
});

test('team options return the contract shape', function () {
    fakeLinearApi([...linearTeamOptionsOperations()]);

    LinearConnection::factory()->for($this->user, 'owner')->create();

    $this->actingAs($this->user)
        ->getJson(route('linear.api.team-options', 'team-1'))
        ->assertOk()
        ->assertExactJson([
            'states' => [
                ['id' => 'state-1', 'name' => 'Triage', 'type' => 'triage'],
                ['id' => 'state-2', 'name' => 'Todo', 'type' => 'unstarted'],
            ],
            'projects' => [['id' => 'project-1', 'name' => 'Inbox']],
            'members' => [['id' => 'user-1', 'name' => 'ada']],
            'labels' => [['id' => 'label-1', 'name' => 'Bug', 'color' => '#f00']],
        ]);
});

test('Linear failures map to the contract error responses', function (array $error, int $status, array $json) {
    fakeLinearApi(['Teams' => Http::response(['errors' => [$error]], 400)]);
    LinearConnection::factory()->for($this->user, 'owner')->create(['refresh_token' => null]);

    $this->actingAs($this->user)
        ->getJson(route('linear.api.teams'))
        ->assertStatus($status)
        ->assertJson($json);
})->with([
    'rejected credentials' => [['message' => 'x', 'extensions' => ['code' => 'AUTHENTICATION_ERROR']], 409, ['reconnect' => true]],
    'outage' => [['message' => 'x', 'extensions' => ['code' => 'INTERNAL_ERROR']], 503, ['message' => 'Linear is temporarily unavailable. We will retry shortly.']],
    'rate limit' => [['message' => 'x', 'extensions' => ['code' => 'RATELIMITED']], 503, ['message' => 'Linear is rate limiting requests. We will retry shortly.']],
    'permission' => [['message' => 'x', 'extensions' => ['code' => 'FORBIDDEN', 'userPresentableMessage' => 'Nope']], 422, ['errors' => ['linear' => ['Linear denied access: Nope']]]],
]);

test('the API routes answer with JSON even without an Accept header', function () {
    $this->actingAs($this->user)
        ->get(route('linear.api.team-options', 'team-1'))
        ->assertStatus(409)
        ->assertHeader('Content-Type', 'application/json');
});
