<?php

declare(strict_types=1);

use Dniccum\Linear\Actions\BuildConnectUrl;
use Dniccum\Linear\Actions\BuildSettings;
use Dniccum\Linear\Actions\DeleteDestination;
use Dniccum\Linear\Actions\DisconnectLinear;
use Dniccum\Linear\Actions\HandleOAuthCallback;
use Dniccum\Linear\Actions\ListTeamOptions;
use Dniccum\Linear\Actions\ListTeams;
use Dniccum\Linear\Actions\RetryFailedSync;
use Dniccum\Linear\Actions\SaveApiKey;
use Dniccum\Linear\Actions\SaveDestination;
use Dniccum\Linear\Data\Destination;
use Dniccum\Linear\Data\OAuthResult;
use Dniccum\Linear\Enums\LinearSendMode;
use Dniccum\Linear\Enums\LinearSyncStatus;
use Dniccum\Linear\Exceptions\LinearApiException;
use Dniccum\Linear\Facades\Linear;
use Dniccum\Linear\Models\LinearCommentDelivery;
use Dniccum\Linear\Models\LinearConnection;
use Dniccum\Linear\Models\LinearDestination;
use Dniccum\Linear\Models\LinearIssueLink;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Workbench\App\Models\Ticket;
use Workbench\App\Models\User;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->session = new Store('test', new ArraySessionHandler(120));
});

test('BuildConnectUrl binds the round trip to the session', function () {
    fakeLinearApi();

    $url = app(BuildConnectUrl::class)->execute($this->session);

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($this->session->get('linear_oauth'))->toHaveKeys(['state', 'code_verifier'])
        ->and($query['state'])->toBe($this->session->get('linear_oauth.state'))
        ->and(strlen($this->session->get('linear_oauth.code_verifier')))->toBe(96);
});

test('BuildConnectUrl refuses to run without client credentials', function () {
    config(['linear.client_id' => null]);

    expect(fn () => app(BuildConnectUrl::class)->execute($this->session))->toThrow(LinearApiException::class, 'not configured');
});

test('HandleOAuthCallback connects the owner', function () {
    fakeLinearApi(['Viewer' => linearViewer()]);
    $this->session->put('linear_oauth', ['state' => 's', 'code_verifier' => 'v']);

    $result = app(HandleOAuthCallback::class)->execute($this->owner, $this->session, 's', 'code');

    expect($result)->toBeInstanceOf(OAuthResult::class)
        ->connected->toBeTrue()
        ->message->toBe('Connected to Acme on Linear.')
        ->and($result->connection?->owner?->is($this->owner))->toBeTrue()
        ->and($this->session->has('linear_oauth'))->toBeFalse();
});

test('HandleOAuthCallback explains what went wrong', function (?string $state, ?string $code, ?string $error, string $message) {
    fakeLinearApi();
    $this->session->put('linear_oauth', ['state' => 'expected', 'code_verifier' => 'v']);

    $result = app(HandleOAuthCallback::class)->execute($this->owner, $this->session, $state, $code, $error);

    expect($result->connected)->toBeFalse()->and($result->connection)->toBeNull()->and($result->message)->toContain($message);
})->with([
    'no state' => [null, 'code', null, 'could not verify'],
    'wrong state' => ['forged', 'code', null, 'could not verify'],
    'denied' => ['expected', null, 'access_denied', 'cancelled'],
    'no code' => ['expected', '', null, 'cancelled'],
    'blank error' => ['expected', null, '', 'cancelled'],
]);

test('SaveApiKey stores the validated key', function () {
    fakeLinearApi(['Viewer' => linearViewer()]);

    $connection = app(SaveApiKey::class)->execute($this->owner, ' lin_api_key ');

    expect($connection)->access_token->toBe('lin_api_key')->usesApiKey()->toBeTrue();
});

test('SaveApiKey rejects a key Linear does not accept', function () {
    fakeLinearApi(['Viewer' => Http::response(['errors' => [['message' => 'x', 'extensions' => ['code' => 'AUTHENTICATION_ERROR']]]], 401)]);

    expect(fn () => app(SaveApiKey::class)->execute($this->owner, 'nope'))->toThrow(ValidationException::class);
});

test('SaveApiKey passes an outage through', function () {
    fakeLinearApi(['Viewer' => Http::response(['errors' => [['message' => 'Down']]], 503)]);

    expect(fn () => app(SaveApiKey::class)->execute($this->owner, 'key'))->toThrow(LinearApiException::class, 'temporarily unavailable');
});

test('DisconnectLinear reports whether there was anything to disconnect', function () {
    fakeLinearApi();

    expect(app(DisconnectLinear::class)->execute($this->owner))->toBeFalse();

    LinearConnection::factory()->for($this->owner, 'owner')->create();

    expect(app(DisconnectLinear::class)->execute($this->owner))->toBeTrue()
        ->and($this->owner->hasLinearConnection())->toBeFalse();
});

test('DisconnectLinear only fails work that is pending for this owner', function () {
    fakeLinearApi();
    LinearConnection::factory()->for($this->owner, 'owner')->create();
    $stranger = User::factory()->create();

    $mine = LinearIssueLink::factory()->for(Ticket::factory()->for($this->owner)->create(), 'linkable')->for($this->owner, 'owner')->create();
    $synced = LinearIssueLink::factory()->for(Ticket::factory()->for($this->owner)->create(), 'linkable')->for($this->owner, 'owner')->synced()->create();
    $theirs = LinearIssueLink::factory()->for(Ticket::factory()->for($stranger)->create(), 'linkable')->for($stranger, 'owner')->create();
    $myComment = LinearCommentDelivery::factory()->for($synced, 'issueLink')->create();
    $theirComment = LinearCommentDelivery::factory()->for($theirs, 'issueLink')->create();

    app(DisconnectLinear::class)->execute($this->owner);

    expect($mine->fresh()->status)->toBe(LinearSyncStatus::Failed)
        ->and($synced->fresh()->status)->toBe(LinearSyncStatus::Synced)
        ->and($theirs->fresh()->status)->toBe(LinearSyncStatus::Pending)
        ->and($myComment->fresh()->status)->toBe(LinearSyncStatus::Failed)
        ->and($theirComment->fresh()->status)->toBe(LinearSyncStatus::Pending);
});

test('ListTeams and ListTeamOptions need a connection', function () {
    expect(fn () => app(ListTeams::class)->execute($this->owner))->toThrow(LinearApiException::class, 'not connected')
        ->and(fn () => app(ListTeamOptions::class)->execute($this->owner, 'team-1'))->toThrow(LinearApiException::class, 'not connected');
});

test('ListTeams and ListTeamOptions read from Linear', function () {
    fakeLinearApi([
        ...linearTeamOptionsOperations(),
        'Teams' => ['teams' => ['nodes' => [['id' => 'team-1', 'name' => 'Support', 'key' => 'SUP']]]],
    ]);
    LinearConnection::factory()->for($this->owner, 'owner')->create();

    expect(app(ListTeams::class)->execute($this->owner))->toHaveCount(1)
        ->and(app(ListTeamOptions::class)->execute($this->owner, 'team-1')->team->key)->toBe('SUP');
});

test('SaveDestination saves and DeleteDestination removes the owner destination', function () {
    fakeLinearApi([...linearTeamOptionsOperations()]);
    LinearConnection::factory()->for($this->owner, 'owner')->create();

    $destination = app(SaveDestination::class)->execute($this->owner, new Destination('team-1', labelIds: ['label-1']));

    expect($destination)->send_mode->toBe(LinearSendMode::Automatic)->team_name->toBe('Support')->label_ids->toBe(['label-1']);

    app(SaveDestination::class)->execute($this->owner, new Destination('team-1'), LinearSendMode::Manual);

    expect(LinearDestination::count())->toBe(1)
        ->and($this->owner->linearDestination()->first()->send_mode)->toBe(LinearSendMode::Manual);

    app(DeleteDestination::class)->execute($this->owner);

    expect(LinearDestination::count())->toBe(0);
});

test('SaveDestination needs a connection', function () {
    expect(fn () => app(SaveDestination::class)->execute($this->owner, new Destination('team-1')))->toThrow(LinearApiException::class, 'not connected');
});

test('RetryFailedSync requeues the owner\'s failed issue', function () {
    fakeLinearApi(['FindIssue' => ['issue' => null], 'CreateIssue' => linearIssueCreated()]);
    LinearConnection::factory()->for($this->owner, 'owner')->create();
    $link = LinearIssueLink::factory()->for(Ticket::factory()->create(['user_id' => null]), 'linkable')->for($this->owner, 'owner')->failed()->create();

    app(RetryFailedSync::class)->execute($this->owner, $link->id);

    expect($link->fresh()->status)->toBe(LinearSyncStatus::Synced);
});

test('RetryFailedSync never touches another owner\'s link', function () {
    LinearConnection::factory()->for($this->owner, 'owner')->create();
    $stranger = User::factory()->create();
    $link = LinearIssueLink::factory()->for(Ticket::factory()->create(['user_id' => null]), 'linkable')->for($stranger, 'owner')->failed()->create();

    expect(fn () => app(RetryFailedSync::class)->execute($this->owner, $link->id))->toThrow(ModelNotFoundException::class);
});

test('RetryFailedSync needs a healthy connection', function () {
    $link = LinearIssueLink::factory()->for(Ticket::factory()->create(['user_id' => null]), 'linkable')->for($this->owner, 'owner')->failed()->create();

    expect(fn () => app(RetryFailedSync::class)->execute($this->owner, $link->id))->toThrow(ValidationException::class);

    LinearConnection::factory()->for($this->owner, 'owner')->needsReconnect()->create();

    expect(fn () => app(RetryFailedSync::class)->execute($this->owner, $link->id))->toThrow(ValidationException::class);
});

test('the retry endpoint requeues a failed issue and answers ok', function () {
    fakeLinearApi(['FindIssue' => ['issue' => null], 'CreateIssue' => linearIssueCreated()]);
    LinearConnection::factory()->for($this->owner, 'owner')->create();
    $link = LinearIssueLink::factory()->for(Ticket::factory()->create(['user_id' => null]), 'linkable')->for($this->owner, 'owner')->failed()->create();

    $this->actingAs($this->owner)
        ->postJson(route('linear.api.issues.retry', $link->id))
        ->assertOk()
        ->assertExactJson(['ok' => true]);

    expect($link->fresh()->status)->toBe(LinearSyncStatus::Synced);
});

test('the retry endpoint is a 404 for links of other owners and a 422 without a healthy connection', function () {
    LinearConnection::factory()->for($this->owner, 'owner')->create();
    $stranger = User::factory()->create();
    $theirs = LinearIssueLink::factory()->for(Ticket::factory()->create(['user_id' => null]), 'linkable')->for($stranger, 'owner')->failed()->create();

    $this->actingAs($this->owner)->postJson(route('linear.api.issues.retry', $theirs->id))->assertNotFound();
    $this->actingAs($this->owner)->postJson(route('linear.api.issues.retry', 9999))->assertNotFound();

    LinearConnection::query()->delete();
    $mine = LinearIssueLink::factory()->for(Ticket::factory()->create(['user_id' => null]), 'linkable')->for($this->owner, 'owner')->failed()->create();

    $this->actingAs($this->owner)->postJson(route('linear.api.issues.retry', $mine->id))->assertUnprocessable()->assertJsonValidationErrors('linear');
});

test('BuildSettings is the action behind Linear::settingsFor()', function () {
    expect(app(BuildSettings::class)->execute($this->owner)->toArray())->toBe(Linear::settingsFor($this->owner)->toArray());
});
