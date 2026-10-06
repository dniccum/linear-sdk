<?php

declare(strict_types=1);

use Dniccum\Linear\Data\IssuePayload;
use Dniccum\Linear\Enums\LinearIssueSource;
use Dniccum\Linear\Enums\LinearSyncStatus;
use Dniccum\Linear\Exceptions\LinearApiException;
use Dniccum\Linear\Models\LinearConnection;
use Dniccum\Linear\Models\LinearDestination;
use Dniccum\Linear\Models\LinearIssueLink;
use Dniccum\Linear\Tests\Fixtures\CustomTicket;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Workbench\App\Models\Ticket;
use Workbench\App\Models\User;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->ticket = Ticket::factory()->for($this->owner)->create(['title' => 'Billing question', 'body' => 'Why was I charged twice?']);
});

test('an owner can file a model as a Linear issue by hand', function () {
    fakeLinearApi(['CreateIssue' => linearIssueCreated('SUP-88')]);
    LinearConnection::factory()->for($this->owner, 'owner')->create();

    $link = $this->ticket->sendToLinear([
        'title' => 'Double charge',
        'description' => 'Customer was billed twice.',
        'team_id' => 'team-1',
        'project_id' => 'project-1',
        'assignee_id' => 'user-1',
        'priority' => 1,
    ]);

    expect($link->fresh())
        ->source->toBe(LinearIssueSource::Manual)
        ->status->toBe(LinearSyncStatus::Synced)
        ->linear_issue_identifier->toBe('SUP-88')
        ->and($this->ticket->fresh())
        ->linear_issue_url->toBe('https://linear.app/acme/issue/SUP-88')
        ->linear_issue_identifier->toBe('SUP-88')
        ->linear_sync_status->toBe(LinearSyncStatus::Synced);

    Http::assertSent(function (Request $request) {
        $input = $request['variables']['input'] ?? null;

        return $input !== null
            && $input['title'] === 'Double charge'
            && $input['description'] === 'Customer was billed twice.'
            && $input['projectId'] === 'project-1'
            && $input['assigneeId'] === 'user-1'
            && $input['priority'] === 1;
    });
});

test('the owner destination supplies the defaults and the overrides win', function () {
    fakeLinearApi(['CreateIssue' => linearIssueCreated()]);
    LinearConnection::factory()->for($this->owner, 'owner')->create();
    LinearDestination::factory()->for($this->owner, 'owner')->manual()->create([
        'team_id' => 'team-saved',
        'state_id' => 'state-saved',
        'label_ids' => ['label-saved'],
        'priority' => 2,
    ]);

    $this->ticket->sendToLinear(['priority' => 4]);

    Http::assertSent(function (Request $request) {
        $input = $request['variables']['input'] ?? [];

        return $input['teamId'] === 'team-saved'
            && $input['stateId'] === 'state-saved'
            && $input['labelIds'] === ['label-saved']
            && $input['priority'] === 4
            && $input['title'] === 'Billing question'
            && str_contains($input['description'], 'Why was I charged twice?');
    });
});

test('content is composed when the model is sent, not later', function () {
    fakeLinearApi(['CreateIssue' => linearIssueCreated()]);
    LinearConnection::factory()->for($this->owner, 'owner')->create();

    $link = $this->ticket->sendToLinear(['team_id' => 'team-1']);

    expect($link->payload)
        ->toBeInstanceOf(IssuePayload::class)
        ->title->toBe('Billing question')
        ->hasContent()->toBeTrue();
});

test('a model with a destination override is sent there', function () {
    fakeLinearApi(['CreateIssue' => linearIssueCreated()]);
    LinearConnection::factory()->for($this->owner, 'owner')->create();
    LinearDestination::factory()->for($this->owner, 'owner')->manual()->create(['team_id' => 'team-saved']);

    $custom = CustomTicket::query()->findOrFail($this->ticket->id);
    $custom->sendToLinear();

    Http::assertSent(fn (Request $request) => ($request['variables']['input']['teamId'] ?? null) === 'team-override'
        && $request['variables']['input']['priority'] === 1
        && $request['variables']['input']['title'] === 'Custom: Billing question'
        && $request['variables']['input']['description'] === 'Custom description');
});

test('sending requires a connection', function () {
    fakeLinearApi();

    expect(fn () => $this->ticket->sendToLinear(['team_id' => 'team-1']))
        ->toThrow(LinearApiException::class, 'Linear is not connected');
});

test('sending is refused when the connection needs reauthorization', function () {
    LinearConnection::factory()->for($this->owner, 'owner')->needsReconnect()->create();

    expect(fn () => $this->ticket->sendToLinear(['team_id' => 'team-1']))
        ->toThrow(LinearApiException::class, 'needs to be reauthorized');
});

test('sending requires an owner', function () {
    $orphan = Ticket::factory()->create(['user_id' => null]);

    expect(fn () => $orphan->sendToLinear(['team_id' => 'team-1']))
        ->toThrow(LinearApiException::class, 'no Linear owner');
});

test('sending requires a team', function () {
    LinearConnection::factory()->for($this->owner, 'owner')->create();

    expect(fn () => $this->ticket->sendToLinear())
        ->toThrow(LinearApiException::class, 'No Linear team is configured');
});

test('a model that is already linked cannot be filed again', function () {
    fakeLinearApi();
    LinearConnection::factory()->for($this->owner, 'owner')->create();
    LinearIssueLink::factory()->for($this->ticket, 'linkable')->for($this->owner, 'owner')->synced()->create();

    expect(fn () => $this->ticket->sendToLinear(['team_id' => 'team-1']))
        ->toThrow(LinearApiException::class, 'already linked to SUP-1');

    Http::assertNothingSent();
});

test('a model whose issue is still being created cannot be filed again', function () {
    LinearConnection::factory()->for($this->owner, 'owner')->create();
    LinearIssueLink::factory()->for($this->ticket, 'linkable')->for($this->owner, 'owner')->create();

    expect(fn () => $this->ticket->sendToLinear(['team_id' => 'team-1']))
        ->toThrow(LinearApiException::class, 'already being created');
});

test('a failed automatic issue can be filed manually, reusing its issue id', function () {
    fakeLinearApi([
        'FindIssue' => ['issue' => null],
        'CreateIssue' => linearIssueCreated(),
    ]);
    LinearConnection::factory()->for($this->owner, 'owner')->create();
    $link = LinearIssueLink::factory()->for($this->ticket, 'linkable')->for($this->owner, 'owner')->failed()->create();

    $this->ticket->sendToLinear(['team_id' => 'team-1', 'title' => 'Help']);

    expect(LinearIssueLink::count())->toBe(1)
        ->and($link->fresh())
        ->source->toBe(LinearIssueSource::Manual)
        ->status->toBe(LinearSyncStatus::Synced)
        ->last_error->toBeNull()
        ->linear_issue_id->toBe($link->linear_issue_id);
});

test('re-filing adopts an issue an earlier attempt already created instead of applying new fields', function () {
    fakeLinearApi([
        'FindIssue' => fn (Request $request) => ['issue' => [
            'id' => $request['variables']['id'],
            'identifier' => 'SUP-5',
            'url' => 'https://linear.app/acme/issue/SUP-5',
        ]],
    ]);
    LinearConnection::factory()->for($this->owner, 'owner')->create();
    $link = LinearIssueLink::factory()->for($this->ticket, 'linkable')->for($this->owner, 'owner')->failed()->create();

    $returned = $this->ticket->sendToLinear(['team_id' => 'team-1', 'title' => 'A different title']);

    expect($returned->isSynced())->toBeTrue()
        ->and($link->fresh())
        ->status->toBe(LinearSyncStatus::Synced)
        ->linear_issue_identifier->toBe('SUP-5')
        ->and(linearOperationsSent())->not->toContain('CreateIssue');
});

test('re-filing into another workspace starts over with a new issue id', function () {
    fakeLinearApi(['CreateIssue' => linearIssueCreated()]);
    LinearConnection::factory()->for($this->owner, 'owner')->create(['linear_organization_id' => 'org-2']);
    $link = LinearIssueLink::factory()->for($this->ticket, 'linkable')->for($this->owner, 'owner')->failed()->create(['linear_organization_id' => 'org-1']);

    $this->ticket->sendToLinear(['team_id' => 'team-9']);

    expect($link->fresh())
        ->linear_issue_id->not->toBe($link->linear_issue_id)
        ->linear_organization_id->toBe('org-2')
        ->status->toBe(LinearSyncStatus::Synced)
        ->and(linearOperationsSent())->toBe(['CreateIssue']);
});

test('a failed issue is retried from the model', function () {
    fakeLinearApi(['FindIssue' => ['issue' => null], 'CreateIssue' => linearIssueCreated()]);
    LinearConnection::factory()->for($this->owner, 'owner')->create();
    LinearIssueLink::factory()->for($this->ticket, 'linkable')->for($this->owner, 'owner')->failed()->create();

    $this->ticket->retryLinear();

    expect($this->ticket->fresh()->linear_sync_status)->toBe(LinearSyncStatus::Synced);
});

test('retrying a model that was never filed does nothing', function () {
    fakeLinearApi();

    $this->ticket->retryLinear();

    Http::assertNothingSent();
});
