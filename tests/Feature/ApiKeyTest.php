<?php

declare(strict_types=1);

use Dniccum\Linear\Enums\LinearAuthMode;
use Dniccum\Linear\Enums\LinearConnectionStatus;
use Dniccum\Linear\Linear;
use Dniccum\Linear\Models\LinearConnection;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Workbench\App\Models\User;

beforeEach(function () {
    config(['linear.auth_mode' => 'api_key']);
    $this->user = User::factory()->create();
});

test('saving an API key validates it against Linear and stores it encrypted', function () {
    fakeLinearApi(['Viewer' => linearViewer()]);

    $this->actingAs($this->user)
        ->post(route('linear.api-key.store'), ['api_key' => '  lin_api_secret  '])
        ->assertRedirect(route('linear.settings'))
        ->assertSessionHas('linear_status', 'Connected to Acme on Linear.');

    $connection = $this->user->fresh()->linearConnection;

    expect($connection)
        ->auth_type->toBe(LinearAuthMode::ApiKey)
        ->access_token->toBe('lin_api_secret')
        ->refresh_token->toBeNull()
        ->token_expires_at->toBeNull()
        ->scopes->toBeNull()
        ->status->toBe(LinearConnectionStatus::Active)
        ->linear_organization_id->toBe('org-1')
        ->organization_name->toBe('Acme')
        ->linear_user_email->toBe('ada@acme.test');

    expect(DB::table('linear_connections')->value('access_token'))->not->toContain('lin_api_secret');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/graphql')
        && $request->hasHeader('Authorization', 'lin_api_secret')
        && str_contains((string) $request['query'], 'viewer'));
});

test('the camelCase field name is accepted too', function () {
    fakeLinearApi(['Viewer' => linearViewer()]);

    $this->actingAs($this->user)->post(route('linear.api-key.store'), ['apiKey' => 'lin_api_secret'])->assertSessionHas('linear_status');

    expect($this->user->fresh()->linearConnection->access_token)->toBe('lin_api_secret');
});

test('a key Linear rejects is reported on the settings page and not stored', function () {
    fakeLinearApi(['Viewer' => Http::response(['errors' => [['message' => 'Authentication required', 'extensions' => ['code' => 'AUTHENTICATION_ERROR']]]], 401)]);

    $this->actingAs($this->user)
        ->post(route('linear.api-key.store'), ['api_key' => 'wrong'])
        ->assertRedirect(route('linear.settings'))
        ->assertSessionHas('linear_error', 'Linear did not accept that API key. Check it and try again.');

    expect($this->user->linearConnection()->exists())->toBeFalse();
});

test('a key Linear rejects is a 422 for JSON clients', function () {
    fakeLinearApi(['Viewer' => Http::response(['errors' => [['message' => 'Authentication required', 'extensions' => ['code' => 'AUTHENTICATION_ERROR']]]], 401)]);

    $this->actingAs($this->user)
        ->postJson(route('linear.api-key.store'), ['api_key' => 'wrong'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('apiKey');
});

test('a missing key is a validation error', function () {
    fakeLinearApi();

    $this->actingAs($this->user)
        ->from(route('linear.settings'))
        ->post(route('linear.api-key.store'), [])
        ->assertRedirect(route('linear.settings'))
        ->assertSessionHasErrors('api_key');

    Http::assertNothingSent();
});

test('an outage while validating the key is reported without storing anything', function () {
    fakeLinearApi(['Viewer' => Http::response(['errors' => [['message' => 'Down']]], 503)]);

    $this->actingAs($this->user)
        ->post(route('linear.api-key.store'), ['api_key' => 'lin_api_secret'])
        ->assertSessionHas('linear_error', 'Linear is temporarily unavailable. We will retry shortly.');

    $this->actingAs($this->user)
        ->postJson(route('linear.api-key.store'), ['api_key' => 'lin_api_secret'])
        ->assertStatus(503);

    expect($this->user->linearConnection()->exists())->toBeFalse();
});

test('saving a key answers JSON clients with the connection and no secrets', function () {
    fakeLinearApi(['Viewer' => linearViewer()]);

    $this->actingAs($this->user)
        ->postJson(route('linear.api-key.store'), ['api_key' => 'lin_api_secret'])
        ->assertOk()
        ->assertJsonPath('connection.organizationName', 'Acme')
        ->assertJsonPath('connection.status', 'active')
        ->assertJsonMissingPath('connection.accessToken');
});

test('replacing an OAuth connection with a key revokes the old token', function () {
    fakeLinearApi(['Viewer' => linearViewer()]);

    LinearConnection::factory()->for($this->user, 'owner')->create(['access_token' => 'old-oauth-token']);

    $this->actingAs($this->user)->post(route('linear.api-key.store'), ['api_key' => 'lin_api_secret']);

    expect(LinearConnection::count())->toBe(1)
        ->and($this->user->fresh()->linearConnection)
        ->auth_type->toBe(LinearAuthMode::ApiKey)
        ->refresh_token->toBeNull();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/oauth/revoke') && $request['token'] === 'old-oauth-token');
});

test('replacing a key with another does not revoke anything', function () {
    fakeLinearApi(['Viewer' => linearViewer()]);

    LinearConnection::factory()->for($this->user, 'owner')->apiKey('lin_api_old')->needsReconnect()->create();

    $this->actingAs($this->user)->post(route('linear.api-key.store'), ['api_key' => 'lin_api_new']);

    expect($this->user->fresh()->linearConnection)
        ->access_token->toBe('lin_api_new')
        ->status->toBe(LinearConnectionStatus::Active)
        ->last_error->toBeNull();

    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/oauth/revoke'));
});

test('saving a key is unavailable in OAuth mode', function () {
    fakeLinearApi();
    config(['linear.auth_mode' => 'oauth']);

    $this->actingAs($this->user)->post(route('linear.api-key.store'), ['api_key' => 'lin_api_secret'])->assertNotFound();
});

test('an unrecognised auth mode means OAuth', function () {
    config(['linear.auth_mode' => 'nonsense']);

    expect(app(Linear::class)->authMode())->toBe(LinearAuthMode::OAuth);

    config(['linear.auth_mode' => null]);

    expect(app(Linear::class)->authMode())->toBe(LinearAuthMode::OAuth);
});
