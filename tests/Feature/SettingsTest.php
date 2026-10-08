<?php

declare(strict_types=1);

use Dniccum\Linear\Data\Settings\BrandData;
use Dniccum\Linear\Data\Settings\SettingsData;
use Dniccum\Linear\Enums\LinearAuthMode;
use Dniccum\Linear\Enums\LinearConnectionStatus;
use Dniccum\Linear\Enums\LinearSyncStatus;
use Dniccum\Linear\Facades\Linear;
use Dniccum\Linear\Models\LinearCommentDelivery;
use Dniccum\Linear\Models\LinearConnection;
use Dniccum\Linear\Models\LinearDestination;
use Dniccum\Linear\Models\LinearIssueLink;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Workbench\App\Models\Ticket;
use Workbench\App\Models\User;

beforeEach(function () {
    $this->owner = User::factory()->create();
});

/**
 * The shape docs/http-contract.md promises, key for key.
 */
test('the settings payload has exactly the keys of the HTTP contract', function () {
    fakeLinearApi();
    $ticket = Ticket::factory()->for($this->owner)->create();
    LinearConnection::factory()->for($this->owner, 'owner')->create(['last_synced_at' => now()]);
    LinearDestination::factory()->for($this->owner, 'owner')->create(['project_id' => 'project-1', 'priority' => 2]);
    LinearIssueLink::factory()->for($ticket, 'linkable')->for($this->owner, 'owner')->failed()->create();

    $payload = Linear::settingsFor($this->owner)->toArray();

    expect(array_keys($payload))->toBe(['configured', 'authMode', 'brand', 'csrf', 'urls', 'connection', 'destination', 'failures', 'flash'])
        ->and(array_keys($payload['brand']))->toBe(['name', 'logo', 'color'])
        ->and(array_keys($payload['urls']))->toBe(['connect', 'apiKey', 'disconnect', 'teams', 'teamOptions', 'destination', 'retry', 'login'])
        ->and(array_keys($payload['connection']))->toBe(['status', 'organizationName', 'organizationUrlKey', 'userName', 'userEmail', 'lastError', 'lastSyncedAt'])
        ->and(array_keys($payload['destination']))->toBe(['sendMode', 'teamId', 'teamName', 'projectId', 'stateId', 'labelIds', 'priority', 'assigneeId'])
        ->and(array_keys($payload['failures'][0]))->toBe(['id', 'kind', 'subject', 'message', 'attempts', 'occurredAt', 'linkId'])
        ->and(array_keys($payload['flash']))->toBe(['status', 'error']);

    expect($payload)->toMatchArray([
        'configured' => true,
        'authMode' => 'oauth',
        'brand' => ['name' => 'Linear', 'logo' => null, 'color' => '#5E6AD2'],
    ])
        ->and($payload['connection'])->toMatchArray([
            'status' => 'active',
            'organizationName' => 'Acme',
            'organizationUrlKey' => 'acme',
            'lastError' => null,
        ])
        ->and($payload['connection']['lastSyncedAt'])->toBeString()
        ->and($payload['destination'])->toMatchArray([
            'sendMode' => 'automatic',
            'teamId' => 'team-1',
            'teamName' => 'Support',
            'projectId' => 'project-1',
            'labelIds' => [],
            'priority' => 2,
        ])
        ->and($payload['flash'])->toBe(['status' => null, 'error' => null]);
});

test('the payload is JSON serializable', function () {
    $settings = Linear::settingsFor($this->owner);

    expect(json_decode(json_encode($settings, JSON_THROW_ON_ERROR), true))->toBe($settings->toArray());
});

test('a disconnected owner has no connection and no destination', function () {
    $payload = Linear::settingsFor($this->owner)->toArray();

    expect($payload['connection'])->toBeNull()
        ->and($payload['destination'])->toBeNull()
        ->and($payload['failures'])->toBe([]);
});

test('the connection health is part of the payload', function () {
    LinearConnection::factory()->for($this->owner, 'owner')->needsReconnect()->create();

    expect(Linear::settingsFor($this->owner)->connection)
        ->status->toBe(LinearConnectionStatus::NeedsReconnect)
        ->lastError->toBe('Linear rejected your authorization.');
});

test('the endpoints are the named routes, with literal placeholders', function () {
    $urls = Linear::settingsFor($this->owner)->toArray()['urls'];

    expect($urls)->toBe([
        'connect' => url('linear/connect'),
        'apiKey' => url('linear/api-key'),
        'disconnect' => url('linear'),
        'teams' => url('linear/api/teams'),
        'teamOptions' => url('linear/api/teams/{team}/options'),
        'destination' => url('linear/api/destination'),
        'retry' => url('linear/api/issues/{link}/retry'),
        'login' => '',
    ]);
});

test('endpoints of a disabled route group are empty', function () {
    config(['linear.routes.oauth' => false, 'linear.routes.api' => false]);
    reloadLinearRoutes();

    expect(Linear::settingsFor($this->owner)->urls->toArray())->toBe([
        'connect' => '',
        'apiKey' => '',
        'disconnect' => '',
        'teams' => '',
        'teamOptions' => '',
        'destination' => '',
        'retry' => '',
        'login' => '',
    ]);
});

test('OAuth is configured only with client credentials, an API key always is', function () {
    config(['linear.client_id' => null, 'linear.client_secret' => null]);

    expect(Linear::settingsFor($this->owner)->configured)->toBeFalse();

    config(['linear.auth_mode' => 'api_key']);

    expect(Linear::settingsFor($this->owner))
        ->configured->toBeTrue()
        ->authMode->toBe(LinearAuthMode::ApiKey)
        ->and(Linear::authMode())->toBe(LinearAuthMode::ApiKey);

    config(['linear.auth_mode' => 'oauth', 'linear.client_id' => 'id', 'linear.client_secret' => 'secret']);

    expect(Linear::settingsFor($this->owner)->configured)->toBeTrue();
});

test('the brand comes from config with sane fallbacks', function () {
    config(['linear.brand' => ['name' => 'Acme Support', 'logo' => 'https://acme.test/logo.svg', 'color' => '#0af']]);

    expect(BrandData::fromConfig()->toArray())->toBe(['name' => 'Acme Support', 'logo' => 'https://acme.test/logo.svg', 'color' => '#0af']);

    config(['linear.brand' => ['name' => '', 'logo' => '', 'color' => 'red; background: url(x)']]);

    expect(BrandData::fromConfig()->toArray())->toBe(['name' => 'Linear', 'logo' => null, 'color' => '#5E6AD2']);

    config(['linear.brand' => null]);

    expect(BrandData::fromConfig()->toArray())->toBe(['name' => 'Linear', 'logo' => null, 'color' => '#5E6AD2']);

    foreach (['#abc', '#abcd', '#aabbcc', '#aabbccdd', '#AABBCC'] as $color) {
        config(['linear.brand.color' => $color]);

        expect(BrandData::fromConfig()->color)->toBe($color);
    }

    foreach (['#ab', '#abcde', 'abc', '#ggg', '#aabbccddee'] as $color) {
        config(['linear.brand.color' => $color]);

        expect(BrandData::fromConfig()->color)->toBe('#5E6AD2');
    }
});

test('failed issues and comments are listed newest first, for this owner only', function () {
    $mine = Ticket::factory()->for($this->owner)->create(['title' => 'Mine']);
    $stranger = User::factory()->create();
    $theirs = Ticket::factory()->for($stranger)->create();

    $this->travelTo(now()->subHours(3));
    $oldIssue = LinearIssueLink::factory()->for($mine, 'linkable')->for($this->owner, 'owner')->failed('Issue went wrong.')->create([
        'payload' => ['team_id' => 'team-1', 'title' => 'Printed title', 'description' => 'D'],
        'attempts' => 5,
    ]);

    $this->travelTo(now()->addHours(1));
    $syncedLink = LinearIssueLink::factory()->for(Ticket::factory()->for($this->owner)->create(), 'linkable')->for($this->owner, 'owner')->synced()->create();
    $commentFailure = LinearCommentDelivery::factory()->for($syncedLink, 'issueLink')->create([
        'status' => LinearSyncStatus::Failed,
        'last_error' => 'Comment went wrong.',
        'attempts' => 2,
    ]);

    $this->travelTo(now()->addHours(1));
    LinearIssueLink::factory()->for($theirs, 'linkable')->for($stranger, 'owner')->failed()->create();
    LinearIssueLink::factory()->for(Ticket::factory()->for($this->owner)->create(), 'linkable')->for($this->owner, 'owner')->create();

    $failures = Linear::settingsFor($this->owner)->toArray()['failures'];

    expect($failures)->toHaveCount(2)
        ->and($failures[0])->toMatchArray([
            'id' => "comment-{$commentFailure->id}",
            'kind' => 'comment',
            'subject' => 'Help',
            'message' => 'Comment went wrong.',
            'attempts' => 2,
            'linkId' => (string) $syncedLink->id,
        ])
        ->and($failures[1])->toMatchArray([
            'id' => "issue-{$oldIssue->id}",
            'kind' => 'issue',
            'subject' => 'Printed title',
            'message' => 'Issue went wrong.',
            'attempts' => 5,
            'linkId' => (string) $oldIssue->id,
        ])
        ->and($failures[0]['occurredAt'])->toBeString();
});

test('a failure without a composed title is named after its model', function () {
    $ticket = Ticket::factory()->for($this->owner)->create();
    LinearIssueLink::factory()->for($ticket, 'linkable')->for($this->owner, 'owner')->failed()->create(['payload' => ['team_id' => 'team-1']]);

    expect(Linear::settingsFor($this->owner)->failures[0]->subject)->toBe("Ticket #{$ticket->id}");
});

test('only the latest ten failures are listed', function () {
    foreach (range(1, 12) as $i) {
        LinearIssueLink::factory()->for(Ticket::factory()->for($this->owner)->create(), 'linkable')->for($this->owner, 'owner')->failed()->create();
    }

    expect(Linear::settingsFor($this->owner)->failures)->toHaveCount(10);
});

test('flash messages come from the session', function () {
    $this->withSession(['linear_status' => 'Connected.', 'linear_error' => 'Oops.']);
    $this->actingAs($this->owner)->get(route('linear.settings'));

    $flash = Linear::settingsFor($this->owner)->flash;

    expect($flash->status)->toBe('Connected.')
        ->and($flash->error)->toBe('Oops.');
});

test('a validation error from a plain form post is shown as the error', function () {
    $bag = new ViewErrorBag;
    $bag->put('default', new MessageBag(['api_key' => ['The API key field is required.']]));

    $this->withSession(['errors' => $bag])->actingAs($this->owner)->get(route('linear.settings'));

    expect(Linear::settingsFor($this->owner)->flash->error)->toBe('The API key field is required.');
});

test('outside a request with a session there is nothing flashed', function () {
    expect(Linear::settingsFor($this->owner)->flash->toArray())->toBe(['status' => null, 'error' => null]);
});

test('the CSRF token is part of the payload', function () {
    $this->actingAs($this->owner)->get(route('linear.settings'));

    expect(Linear::settingsFor($this->owner)->csrf)->toBe(csrf_token())->not->toBe('');
});

test('settings can be rendered as JSON that is safe inside a script element', function () {
    LinearConnection::factory()->for($this->owner, 'owner')->create(['organization_name' => '</script><script>alert(1)</script> & "quoted" \'single\'']);

    $json = Linear::settingsFor($this->owner)->toScriptJson();

    expect($json)->not->toContain('</script>')->not->toContain('<')->not->toContain('&')
        ->and(json_decode($json, true)['connection']['organizationName'])->toBe('</script><script>alert(1)</script> & "quoted" \'single\'');
});

test('settings DTOs are plain data', function () {
    $settings = Linear::settingsFor($this->owner);

    expect($settings)->toBeInstanceOf(SettingsData::class)
        ->and($settings->toArray())->toBe($settings->jsonSerialize());
});
