<?php

declare(strict_types=1);

use Dniccum\Linear\Data\Destination;
use Dniccum\Linear\Data\IssuePayload;
use Dniccum\Linear\Data\Label;
use Dniccum\Linear\Data\Member;
use Dniccum\Linear\Data\Organization;
use Dniccum\Linear\Data\Project;
use Dniccum\Linear\Data\Team;
use Dniccum\Linear\Data\TeamOptions;
use Dniccum\Linear\Data\Tokens;
use Dniccum\Linear\Data\Viewer;
use Dniccum\Linear\Data\WorkflowState;
use Dniccum\Linear\Enums\LinearSyncStatus;
use Dniccum\Linear\Exceptions\LinearApiException;
use Dniccum\Linear\Facades\Linear;
use Dniccum\Linear\Models\LinearConnection;
use Dniccum\Linear\Models\LinearDestination;
use Dniccum\Linear\Services\LinearClient;
use Dniccum\Linear\Services\LinearOAuth;
use Dniccum\Linear\Testing\FakeLinearClient;
use Dniccum\Linear\Testing\FakeLinearOAuth;
use Dniccum\Linear\Testing\LinearFake;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\ExpectationFailedException;
use Workbench\App\Models\Ticket;
use Workbench\App\Models\User;

beforeEach(function () {
    $this->fake = Linear::fake();
    $this->owner = User::factory()->create();
});

test('Linear::fake() replaces the client and OAuth service in the container', function () {
    expect($this->fake)->toBeInstanceOf(LinearFake::class)
        ->and(app(LinearClient::class))->toBe($this->fake->client)->toBeInstanceOf(FakeLinearClient::class)
        ->and(app(LinearOAuth::class))->toBe($this->fake->oauth)->toBeInstanceOf(FakeLinearOAuth::class);
});

test('nothing leaves the process', function () {
    Http::preventStrayRequests();
    LinearConnection::factory()->for($this->owner, 'owner')->create();
    LinearDestination::factory()->for($this->owner, 'owner')->create();

    Ticket::factory()->for($this->owner)->create();

    Http::assertNothingSent();
});

test('it provides OAuth credentials only when none are configured', function () {
    expect(config('linear.client_id'))->toBe('fake-client-id');

    config(['linear.client_id' => 'mine', 'linear.client_secret' => 'secret']);

    Linear::fake();

    expect(config('linear.client_id'))->toBe('mine');
});

test('a model is filed and its issue recorded', function () {
    LinearConnection::factory()->for($this->owner, 'owner')->create();
    LinearDestination::factory()->for($this->owner, 'owner')->create(['team_id' => 'team-1', 'priority' => 2]);

    $ticket = Ticket::factory()->for($this->owner)->create(['title' => 'Cannot upload']);

    $this->fake
        ->assertIssueCreated()
        ->assertIssueCreated(fn (IssuePayload $payload, string $issueId) => $payload->title === 'Cannot upload'
            && $payload->destination->priority === 2
            && $issueId === $ticket->linearIssueLink->linear_issue_id)
        ->assertIssueCreatedCount(1)
        ->assertNoCommentPosted();

    expect($ticket->fresh())
        ->linear_issue_identifier->toBe('FAKE-1')
        ->linear_issue_url->toBe('https://linear.app/fake/issue/FAKE-1')
        ->linear_sync_status->toBe(LinearSyncStatus::Synced)
        ->and($this->fake->createdIssues())->toHaveCount(1);
});

test('comments are recorded', function () {
    LinearConnection::factory()->for($this->owner, 'owner')->create();
    LinearDestination::factory()->for($this->owner, 'owner')->create();
    $ticket = Ticket::factory()->for($this->owner)->create();

    $ticket->commentOnLinear('Looking into it');

    $this->fake->assertCommentPosted()->assertCommentPosted(fn (string $body, string $issueId) => $body === 'Looking into it'
        && $issueId === $ticket->linearIssueLink->linear_issue_id);

    expect($this->fake->postedComments())->toHaveCount(1);
});

test('the assertions fail when they should', function () {
    expect(fn () => $this->fake->assertIssueCreated())->toThrow(ExpectationFailedException::class, 'No matching Linear issue')
        ->and(fn () => $this->fake->assertCommentPosted())->toThrow(ExpectationFailedException::class, 'No matching Linear comment')
        ->and(fn () => $this->fake->assertIssueCreatedCount(2))->toThrow(ExpectationFailedException::class);

    LinearConnection::factory()->for($this->owner, 'owner')->create();
    LinearDestination::factory()->for($this->owner, 'owner')->create();
    $ticket = Ticket::factory()->for($this->owner)->create();
    $ticket->commentOnLinear('Hello');

    expect(fn () => $this->fake->assertIssueCreated(fn () => false))->toThrow(ExpectationFailedException::class)
        ->and(fn () => $this->fake->assertNoIssueCreated())->toThrow(ExpectationFailedException::class)
        ->and(fn () => $this->fake->assertCommentPosted(fn () => false))->toThrow(ExpectationFailedException::class)
        ->and(fn () => $this->fake->assertNoCommentPosted())->toThrow(ExpectationFailedException::class)
        ->and(fn () => $this->fake->assertNothingSent())->toThrow(ExpectationFailedException::class);
});

test('nothing sent holds until the API is called', function () {
    $this->fake->assertNothingSent();
});

test('teams and team options can be arranged', function () {
    LinearConnection::factory()->for($this->owner, 'owner')->create();

    $options = new TeamOptions(
        team: new Team('team-9', 'Bugs', 'BUG'),
        projects: [new Project('p-1', 'Triage')],
        states: [new WorkflowState('s-1', 'Open', 'unstarted')],
        labels: [new Label('l-1', 'Urgent', null)],
        members: [new Member('m-1', 'Grace')],
    );

    $this->fake->withTeams([new Team('team-9', 'Bugs', 'BUG')])->withTeamOptions($options);

    $this->actingAs($this->owner)->getJson(route('linear.api.teams'))->assertExactJson(['teams' => [['id' => 'team-9', 'name' => 'Bugs', 'key' => 'BUG']]]);
    $this->actingAs($this->owner)->getJson(route('linear.api.team-options', 'team-9'))->assertJsonPath('members.0.name', 'Grace');
    $this->actingAs($this->owner)->putJson(route('linear.api.destination.update'), ['sendMode' => 'manual', 'teamId' => 'team-9', 'projectId' => 'p-1'])->assertOk()->assertJsonPath('destination.teamName', 'Bugs');
});

test('teams without arranged options get defaults, unknown teams are rejected', function () {
    LinearConnection::factory()->for($this->owner, 'owner')->create();

    $this->actingAs($this->owner)->getJson(route('linear.api.team-options', 'team-1'))
        ->assertOk()
        ->assertJsonPath('projects.0.id', 'project-1')
        ->assertJsonPath('states.1.id', 'state-2')
        ->assertJsonPath('labels.0.color', '#eb5757')
        ->assertJsonPath('members.0.id', 'user-1');

    $this->actingAs($this->owner)->putJson(route('linear.api.destination.update'), ['sendMode' => 'manual', 'teamId' => 'nope'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('teamId');
});

test('the viewer can be arranged for API key validation', function () {
    config(['linear.auth_mode' => 'api_key']);
    $this->fake->withViewer(new Viewer('v-9', 'Grace Hopper', 'grace@navy.test', new Organization('org-9', 'Navy', 'navy')));

    $this->actingAs($this->owner)->post(route('linear.api-key.store'), ['api_key' => 'anything'])->assertSessionHas('linear_status', 'Connected to Navy on Linear.');

    expect($this->owner->fresh()->linearConnection)->organization_name->toBe('Navy')->linear_user_email->toBe('grace@navy.test');
    expect($this->fake->client->calls('viewer'))->toHaveCount(1);
});

test('the OAuth flow runs against the fake', function () {
    $this->fake->withTokens(new Tokens('tok-9', 'ref-9', 3600, ['read', 'issues:create', 'comments:create']));
    LinearConnection::factory()->for($this->owner, 'owner')->create(['access_token' => 'previous']);

    $this->actingAs($this->owner)
        ->withSession(['linear_oauth' => ['state' => 's', 'code_verifier' => 'v']])
        ->get(route('linear.callback', ['code' => 'code-9', 'state' => 's']))
        ->assertSessionHas('linear_status');

    expect($this->owner->fresh()->linearConnection->access_token)->toBe('tok-9')
        ->and($this->fake->oauth->exchangedCodes())->toBe(['code-9'])
        ->and($this->fake->oauth->revokedTokens())->toBe(['previous'])
        ->and($this->fake->oauth->refresh('whatever')->accessToken)->toBe('tok-9');
});

test('the default fake tokens satisfy the configured scopes', function () {
    expect($this->fake->oauth->exchangeCode('c', 'v')->missingScopes(['read', 'issues:create', 'comments:create']))->toBe([]);
});

test('failures are consumed one call at a time', function () {
    $client = $this->fake->client;
    $connection = LinearConnection::factory()->for($this->owner, 'owner')->create();

    $this->fake->failWith('teams', new LinearApiException('Down', LinearApiException::TRANSIENT), 2);

    expect(fn () => $client->teams($connection))->toThrow(LinearApiException::class)
        ->and(fn () => $client->teams($connection))->toThrow(LinearApiException::class)
        ->and($client->teams($connection))->toHaveCount(1)
        ->and($client->calls('teams'))->toHaveCount(3)
        ->and($client->calls())->toHaveCount(3);
});

test('issues and comments the fake created are found again', function () {
    $client = $this->fake->client;
    $connection = LinearConnection::factory()->for($this->owner, 'owner')->create();

    expect($client->findIssue($connection, 'i-1'))->toBeNull()
        ->and($client->findComment($connection, 'c-1'))->toBeNull();

    $issue = $client->createIssue($connection, 'i-1', new IssuePayload(new Destination('team-1'), 'T', 'D'));
    $second = $client->createIssue($connection, 'i-2', new IssuePayload(new Destination('team-1'), 'T', 'D'));
    $comment = $client->createComment($connection, 'c-1', 'i-1', 'Hi');

    expect($client->findIssue($connection, 'i-1'))->toBe($issue)
        ->and($second->identifier)->toBe('FAKE-2')
        ->and($client->findComment($connection, 'c-1'))->toBe($comment);
});
