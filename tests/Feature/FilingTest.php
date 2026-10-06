<?php

declare(strict_types=1);

use Dniccum\Linear\Enums\LinearIssueSource;
use Dniccum\Linear\Enums\LinearSyncStatus;
use Dniccum\Linear\Models\LinearConnection;
use Dniccum\Linear\Models\LinearDestination;
use Dniccum\Linear\Models\LinearIssueLink;
use Dniccum\Linear\Services\LinearIssueSync;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Workbench\App\Models\Ticket;
use Workbench\App\Models\User;

beforeEach(function () {
    $this->owner = User::factory()->create();
});

test('a new model files exactly one issue through an enabled destination', function () {
    fakeLinearApi(['CreateIssue' => linearIssueCreated('SUP-7')]);
    LinearConnection::factory()->for($this->owner, 'owner')->create();
    LinearDestination::factory()->for($this->owner, 'owner')->create([
        'team_id' => 'team-1',
        'state_id' => 'state-1',
        'label_ids' => ['label-1'],
        'priority' => 3,
    ]);

    $ticket = Ticket::factory()->for($this->owner)->create([
        'title' => 'Cannot upload',
        'body' => "Uploads fail.\nEvery time.",
    ]);

    // A second pass (e.g. a duplicate event) must not file again.
    app(LinearIssueSync::class)->fileAutomatically($ticket->fresh());

    $link = $ticket->fresh()->linearIssueLink;

    expect(LinearIssueLink::count())->toBe(1)
        ->and($link)
        ->source->toBe(LinearIssueSource::Automatic)
        ->status->toBe(LinearSyncStatus::Synced)
        ->linear_issue_identifier->toBe('SUP-7')
        ->linear_issue_url->toBe('https://linear.app/acme/issue/SUP-7')
        ->owner_id->toBe($this->owner->id)
        ->and($link->linkable?->is($ticket))->toBeTrue()
        ->and($link->owner?->is($this->owner))->toBeTrue();

    expect(linearOperationsSent())->toBe(['CreateIssue']);

    Http::assertSent(function (Request $request) use ($link, $ticket) {
        $input = $request['variables']['input'] ?? [];

        return ($input['id'] ?? null) === $link->linear_issue_id
            && $input['teamId'] === 'team-1'
            && $input['stateId'] === 'state-1'
            && $input['labelIds'] === ['label-1']
            && $input['priority'] === 3
            && $input['title'] === 'Cannot upload'
            && str_contains($input['description'], "**Ticket #{$ticket->id}**")
            && str_contains($input['description'], "- Body:\n  > Uploads fail.\n  > Every time.");
    });

    expect($this->owner->linearConnection->fresh()->last_synced_at)->not->toBeNull();
});

test('manual send mode does not file models automatically', function () {
    fakeLinearApi();
    LinearConnection::factory()->for($this->owner, 'owner')->create();
    LinearDestination::factory()->for($this->owner, 'owner')->manual()->create();

    Ticket::factory()->for($this->owner)->create();

    expect(LinearIssueLink::count())->toBe(0);
    Http::assertNothingSent();
});

test('a destination for another workspace does not file issues', function () {
    fakeLinearApi();
    LinearConnection::factory()->for($this->owner, 'owner')->create(['linear_organization_id' => 'org-2']);
    LinearDestination::factory()->for($this->owner, 'owner')->create(['linear_organization_id' => 'org-1']);

    Ticket::factory()->for($this->owner)->create();

    expect(LinearIssueLink::count())->toBe(0);
});

test('a destination without a healthy connection does not file issues', function () {
    fakeLinearApi();
    LinearConnection::factory()->for($this->owner, 'owner')->needsReconnect()->create();
    LinearDestination::factory()->for($this->owner, 'owner')->create();

    Ticket::factory()->for($this->owner)->create();

    expect(LinearIssueLink::count())->toBe(0);
});

test('an owner without a connection or without a destination files nothing', function () {
    fakeLinearApi();

    Ticket::factory()->for($this->owner)->create();

    LinearConnection::factory()->for($this->owner, 'owner')->create();

    Ticket::factory()->for($this->owner)->create();

    LinearDestination::factory()->for($this->owner, 'owner')->create();
    LinearConnection::query()->delete();

    Ticket::factory()->for($this->owner)->create();

    expect(LinearIssueLink::count())->toBe(0);
    Http::assertNothingSent();
});

test('a model without an owner files nothing', function () {
    fakeLinearApi();

    Ticket::factory()->create(['user_id' => null]);

    expect(LinearIssueLink::count())->toBe(0);
});

test('a Linear outage keeps the record and leaves the issue retryable', function () {
    fakeLinearApi(['CreateIssue' => Http::response(['errors' => [['message' => 'Down']]], 503)]);
    LinearConnection::factory()->for($this->owner, 'owner')->create();
    LinearDestination::factory()->for($this->owner, 'owner')->create();

    $ticket = Ticket::factory()->for($this->owner)->create();

    $link = $ticket->fresh()->linearIssueLink;

    expect(Ticket::count())->toBe(1)
        ->and($link->status)->not->toBe(LinearSyncStatus::Synced)
        ->and($link->last_error)->toContain('temporarily unavailable')
        ->and($link->attempts)->toBe(1)
        ->and($this->owner->linearConnection->fresh()->last_error)->toContain('temporarily unavailable');
});

test('a permanent Linear rejection fails the link without retrying', function () {
    fakeLinearApi(['CreateIssue' => Http::response(['errors' => [[
        'message' => 'Entity not found: Team',
        'extensions' => ['code' => 'INVALID_INPUT', 'userPresentableMessage' => 'Team was deleted.'],
    ]]], 400), 'FindIssue' => ['issue' => null]]);
    LinearConnection::factory()->for($this->owner, 'owner')->create();
    LinearDestination::factory()->for($this->owner, 'owner')->create();

    $ticket = Ticket::factory()->for($this->owner)->create();

    expect($ticket->fresh()->linearIssueLink)
        ->status->toBe(LinearSyncStatus::Failed)
        ->last_error->toBe('Team was deleted.');
});

test('a creation that raced another attempt adopts the issue that won', function () {
    fakeLinearApi([
        'CreateIssue' => Http::response(['errors' => [['message' => 'Duplicate id', 'extensions' => ['userPresentableMessage' => 'Issue id already exists.']]]], 400),
        'FindIssue' => fn (Request $request) => ['issue' => [
            'id' => $request['variables']['id'],
            'identifier' => 'SUP-11',
            'url' => 'https://linear.app/acme/issue/SUP-11',
        ]],
    ]);
    LinearConnection::factory()->for($this->owner, 'owner')->create();
    LinearDestination::factory()->for($this->owner, 'owner')->create();

    $ticket = Ticket::factory()->for($this->owner)->create();

    expect($ticket->fresh()->linearIssueLink)
        ->status->toBe(LinearSyncStatus::Synced)
        ->linear_issue_identifier->toBe('SUP-11');
});

test('retrying adopts an issue Linear created before the response was lost', function () {
    fakeLinearApi(['FindIssue' => fn (Request $request) => ['issue' => [
        'id' => $request['variables']['id'],
        'identifier' => 'SUP-9',
        'url' => 'https://linear.app/acme/issue/SUP-9',
    ]]]);
    LinearConnection::factory()->for($this->owner, 'owner')->create();

    $ticket = Ticket::factory()->for($this->owner)->create();
    $link = LinearIssueLink::factory()->for($ticket, 'linkable')->for($this->owner, 'owner')->failed('Could not reach Linear.')->create();

    app(LinearIssueSync::class)->retry($link);

    expect($link->fresh())
        ->status->toBe(LinearSyncStatus::Synced)
        ->linear_issue_identifier->toBe('SUP-9')
        ->last_error->toBeNull();

    expect(linearOperationsSent())->toBe(['FindIssue']);
});

test('retrying does nothing for work that is pending or already synced', function () {
    fakeLinearApi();
    LinearConnection::factory()->for($this->owner, 'owner')->create();

    $ticket = Ticket::factory()->for($this->owner)->create();
    $pending = LinearIssueLink::factory()->for($ticket, 'linkable')->for($this->owner, 'owner')->create();

    app(LinearIssueSync::class)->retry($pending);

    $pending->update(['status' => LinearSyncStatus::Synced]);

    app(LinearIssueSync::class)->retry($pending);

    expect($pending->fresh()->status)->toBe(LinearSyncStatus::Synced);
    Http::assertNothingSent();
});

test('pushing an issue that is already synced is a no-op', function () {
    fakeLinearApi();
    LinearConnection::factory()->for($this->owner, 'owner')->create();

    $ticket = Ticket::factory()->for($this->owner)->create();
    $link = LinearIssueLink::factory()->for($ticket, 'linkable')->for($this->owner, 'owner')->synced()->create();

    app(LinearIssueSync::class)->pushIssue($link);

    Http::assertNothingSent();
});

test('an issue for a workspace other than the connected one is not sent', function () {
    fakeLinearApi();
    LinearConnection::factory()->for($this->owner, 'owner')->create(['linear_organization_id' => 'org-2']);

    $ticket = Ticket::factory()->for($this->owner)->create();
    $link = LinearIssueLink::factory()->for($ticket, 'linkable')->for($this->owner, 'owner')->create(['linear_organization_id' => 'org-1']);

    expect(fn () => app(LinearIssueSync::class)->pushIssue($link))->toThrow(Exception::class, 'not the one this issue was filed in');
});

test('an issue whose owner is gone cannot be sent', function () {
    fakeLinearApi();

    $ticket = Ticket::factory()->for($this->owner)->create();
    $link = LinearIssueLink::factory()->for($ticket, 'linkable')->create();

    expect(fn () => app(LinearIssueSync::class)->pushIssue($link))->toThrow(Exception::class, 'not connected');
});

test('an issue whose model was deleted before the first attempt cannot be composed', function () {
    fakeLinearApi();
    LinearConnection::factory()->for($this->owner, 'owner')->create();

    $ticket = Ticket::factory()->for($this->owner)->create();
    $link = LinearIssueLink::factory()->for($ticket, 'linkable')->for($this->owner, 'owner')->create([
        'payload' => ['team_id' => 'team-1'],
    ]);
    $ticket->delete();

    expect(fn () => app(LinearIssueSync::class)->pushIssue($link->fresh()))->toThrow(Exception::class, 'no longer exists');
});
