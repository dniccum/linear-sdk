<?php

declare(strict_types=1);

use Dniccum\Linear\Enums\LinearSendMode;
use Dniccum\Linear\Models\LinearConnection;
use Dniccum\Linear\Models\LinearDestination;
use Illuminate\Support\Facades\Http;
use Workbench\App\Models\User;

beforeEach(function () {
    $this->owner = User::factory()->create();
});

test('saving a destination validates it against Linear', function () {
    fakeLinearApi([...linearTeamOptionsOperations()]);
    LinearConnection::factory()->for($this->owner, 'owner')->create();

    $this->actingAs($this->owner)
        ->putJson(route('linear.api.destination.update'), [
            'sendMode' => 'automatic',
            'teamId' => 'team-1',
            'projectId' => 'project-1',
            'stateId' => 'state-1',
            'labelIds' => ['label-1'],
            'priority' => 2,
            'assigneeId' => 'user-1',
        ])
        ->assertOk()
        ->assertExactJson(['destination' => [
            'sendMode' => 'automatic',
            'teamId' => 'team-1',
            'teamName' => 'Support',
            'projectId' => 'project-1',
            'stateId' => 'state-1',
            'labelIds' => ['label-1'],
            'priority' => 2,
            'assigneeId' => 'user-1',
        ]]);

    expect($this->owner->linearDestination)
        ->send_mode->toBe(LinearSendMode::Automatic)
        ->linear_organization_id->toBe('org-1')
        ->team_name->toBe('Support')
        ->project_id->toBe('project-1')
        ->label_ids->toBe(['label-1'])
        ->priority->toBe(2);
});

test('saving again updates the one destination', function () {
    fakeLinearApi([...linearTeamOptionsOperations()]);
    LinearConnection::factory()->for($this->owner, 'owner')->create();

    foreach (['automatic', 'manual'] as $mode) {
        $this->actingAs($this->owner)->putJson(route('linear.api.destination.update'), ['sendMode' => $mode, 'teamId' => 'team-1'])->assertOk();
    }

    expect(LinearDestination::count())->toBe(1)
        ->and($this->owner->linearDestination->send_mode)->toBe(LinearSendMode::Manual);
});

test('saving in manual mode keeps the destination without filing automatically', function () {
    fakeLinearApi([...linearTeamOptionsOperations()]);
    LinearConnection::factory()->for($this->owner, 'owner')->create();

    $this->actingAs($this->owner)
        ->putJson(route('linear.api.destination.update'), ['sendMode' => 'manual', 'teamId' => 'team-1'])
        ->assertOk()
        ->assertJsonPath('destination.sendMode', 'manual')
        ->assertJsonPath('destination.priority', 0);

    $destination = $this->owner->linearDestination;

    expect($destination)
        ->send_mode->toBe(LinearSendMode::Manual)
        ->team_id->toBe('team-1')
        ->and($destination->appliesTo($this->owner->linearConnection))->toBeFalse();
});

test('saving rejects an unknown send mode and malformed input', function () {
    fakeLinearApi([...linearTeamOptionsOperations()]);
    LinearConnection::factory()->for($this->owner, 'owner')->create();

    $this->actingAs($this->owner)
        ->putJson(route('linear.api.destination.update'), ['sendMode' => 'sometimes', 'teamId' => 'team-1', 'priority' => 9, 'labelIds' => 'nope'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['sendMode', 'priority', 'labelIds']);

    $this->actingAs($this->owner)
        ->putJson(route('linear.api.destination.update'), ['sendMode' => 'manual'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('teamId');
});

test('saving rejects destinations outside the team', function () {
    fakeLinearApi([...linearTeamOptionsOperations()]);
    LinearConnection::factory()->for($this->owner, 'owner')->create();

    $this->actingAs($this->owner)
        ->putJson(route('linear.api.destination.update'), [
            'sendMode' => 'automatic',
            'teamId' => 'team-1',
            'projectId' => 'project-done',
            'stateId' => 'state-x',
            'labelIds' => ['label-group'],
            'assigneeId' => 'user-gone',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['projectId', 'stateId', 'labelIds', 'assigneeId']);

    expect($this->owner->linearDestination)->toBeNull();
});

test('saving rejects a team the connection cannot see', function () {
    fakeLinearApi(['TeamOptions' => ['team' => null, 'issueLabels' => ['nodes' => []]]]);
    LinearConnection::factory()->for($this->owner, 'owner')->create();

    $this->actingAs($this->owner)
        ->putJson(route('linear.api.destination.update'), ['sendMode' => 'automatic', 'teamId' => 'team-x'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('teamId');
});

test('saving requires a Linear connection', function () {
    $this->actingAs($this->owner)
        ->putJson(route('linear.api.destination.update'), ['sendMode' => 'automatic', 'teamId' => 'team-1'])
        ->assertStatus(409)
        ->assertJson(['reconnect' => true]);
});

test('saving reports an outage instead of treating it as a validation error', function () {
    fakeLinearApi(['TeamOptions' => Http::response(['errors' => [['message' => 'Down']]], 503)]);
    LinearConnection::factory()->for($this->owner, 'owner')->create();

    $this->actingAs($this->owner)
        ->putJson(route('linear.api.destination.update'), ['sendMode' => 'automatic', 'teamId' => 'team-1'])
        ->assertStatus(503);
});

test('a destination can be removed', function () {
    LinearDestination::factory()->for($this->owner, 'owner')->create();

    $this->actingAs($this->owner)
        ->deleteJson(route('linear.api.destination.destroy'))
        ->assertOk()
        ->assertExactJson(['destination' => null]);

    expect($this->owner->fresh()->linearDestination)->toBeNull();
});

test('a destination applies only to an active connection in its own workspace', function () {
    $destination = LinearDestination::factory()->for($this->owner, 'owner')->make(['linear_organization_id' => 'org-1']);
    $connection = LinearConnection::factory()->for($this->owner, 'owner')->make(['linear_organization_id' => 'org-1']);

    expect($destination->appliesTo($connection))->toBeTrue()
        ->and($destination->appliesTo(null))->toBeFalse()
        ->and($destination->appliesTo(LinearConnection::factory()->for($this->owner, 'owner')->make(['linear_organization_id' => 'org-2'])))->toBeFalse()
        ->and($destination->appliesTo(LinearConnection::factory()->for($this->owner, 'owner')->needsReconnect()->make()))->toBeFalse()
        ->and(LinearDestination::factory()->for($this->owner, 'owner')->manual()->make()->appliesTo($connection))->toBeFalse()
        ->and((new LinearDestination(['team_id' => 'team-1']))->appliesTo($connection))->toBeTrue();
});
