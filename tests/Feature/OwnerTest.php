<?php

declare(strict_types=1);

use Dniccum\Linear\Data\Destination;
use Dniccum\Linear\Data\IssuePayload;
use Dniccum\Linear\Exceptions\LinearApiException;
use Dniccum\Linear\Facades\Linear;
use Dniccum\Linear\Models\LinearConnection;
use Dniccum\Linear\Models\LinearDestination;
use Dniccum\Linear\Models\LinearIssueLink;
use Dniccum\Linear\Services\ConnectionClient;
use Dniccum\Linear\Support\ModelHooks;
use Dniccum\Linear\Tests\Fixtures\PlainModel;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Facades\Http;
use Workbench\App\Models\Ticket;
use Workbench\App\Models\User;

test('an owner has a connection and a destination', function () {
    $user = User::factory()->create();
    $connection = LinearConnection::factory()->for($user, 'owner')->create();
    $destination = LinearDestination::factory()->for($user, 'owner')->create();

    expect($user->linearConnection())->toBeInstanceOf(MorphOne::class)
        ->and($user->linearConnection->is($connection))->toBeTrue()
        ->and($user->linearDestination->is($destination))->toBeTrue()
        ->and($connection->owner?->is($user))->toBeTrue()
        ->and($destination->owner?->is($user))->toBeTrue();
});

test('hasLinearConnection reflects the database or the loaded relation', function () {
    $user = User::factory()->create();

    expect($user->hasLinearConnection())->toBeFalse();

    LinearConnection::factory()->for($user, 'owner')->create();

    expect($user->hasLinearConnection())->toBeTrue()
        ->and($user->load('linearConnection')->hasLinearConnection())->toBeTrue();

    $other = User::factory()->create()->load('linearConnection');

    expect($other->hasLinearConnection())->toBeFalse();
});

test('linear_connected is an attribute that can be appended', function () {
    $user = User::factory()->create();

    expect($user->linear_connected)->toBeFalse()
        ->and($user->toArray())->toHaveKey('linear_connected', false);

    LinearConnection::factory()->for($user, 'owner')->create();

    expect($user->fresh()->toArray())->toHaveKey('linear_connected', true)
        ->and($user->fresh()->toArray())->not->toHaveKey('linear_connection');
});

test('disconnecting from the owner revokes and removes the connection', function () {
    fakeLinearApi();
    $user = User::factory()->create();
    LinearConnection::factory()->for($user, 'owner')->create(['access_token' => 'revoke-me']);

    $user->disconnectLinear();

    expect($user->hasLinearConnection())->toBeFalse();
    Http::assertSent(fn ($request) => $request['token'] === 'revoke-me');
});

test('the owner hands out a client bound to its connection', function () {
    fakeLinearApi(['Teams' => ['teams' => ['nodes' => [['id' => 'team-1', 'name' => 'Support', 'key' => 'SUP']]]]]);
    $user = User::factory()->create();
    $connection = LinearConnection::factory()->for($user, 'owner')->create();

    $client = $user->linearClient();

    expect($client)->toBeInstanceOf(ConnectionClient::class)
        ->and($client->connection->is($connection))->toBeTrue()
        ->and($client->teams())->toHaveCount(1)
        ->and(Linear::client($user)->connection->is($connection))->toBeTrue();
});

test('the bound client covers every operation', function () {
    $fake = Linear::fake();
    $user = User::factory()->create();
    LinearConnection::factory()->for($user, 'owner')->create();

    $client = $user->linearClient();

    expect($client->teamOptions('team-1')->team->name)->toBe('Support')
        ->and($client->createIssue('issue-1', new IssuePayload(new Destination('team-1'), 'T', 'D'))->identifier)->toBe('FAKE-1')
        ->and($client->findIssue('issue-1')?->id)->toBe('issue-1')
        ->and($client->findIssue('nope'))->toBeNull()
        ->and($client->createComment('comment-1', 'issue-1', 'Hi')->id)->toBe('comment-1')
        ->and($client->findComment('comment-1')?->id)->toBe('comment-1')
        ->and($client->query('{ viewer { id } }'))->toBe([]);

    expect($fake->client->calls('query'))->toHaveCount(1);
});

test('a client needs a connection', function () {
    $user = User::factory()->create();

    expect(fn () => $user->linearClient())->toThrow(LinearApiException::class, 'not connected')
        ->and(fn () => Linear::client($user))->toThrow(LinearApiException::class, 'not connected');
});

test('the model hooks refuse models that do not use the traits', function () {
    $plain = new PlainModel;

    expect(fn () => ModelHooks::owner($plain))->toThrow(LogicException::class, 'CreatesLinearIssues')
        ->and(fn () => ModelHooks::events($plain))->toThrow(LogicException::class, 'CreatesLinearIssues')
        ->and(fn () => ModelHooks::destinationOverride($plain))->toThrow(LogicException::class, 'CreatesLinearIssues')
        ->and(fn () => ModelHooks::connection($plain))->toThrow(LogicException::class, 'HasLinearConnection')
        ->and(fn () => ModelHooks::destination($plain))->toThrow(LogicException::class, 'HasLinearConnection');
});

test('the model hooks read what the traits expose', function () {
    $user = User::factory()->create();
    $ticket = Ticket::factory()->for($user)->create();
    LinearConnection::factory()->for($user, 'owner')->create();
    LinearDestination::factory()->for($user, 'owner')->create();

    expect(ModelHooks::owner($ticket)?->is($user))->toBeTrue()
        ->and(ModelHooks::events($ticket))->toBe(['created'])
        ->and(ModelHooks::destinationOverride($ticket))->toBeNull()
        ->and(ModelHooks::connection($user))->toBeInstanceOf(LinearConnection::class)
        ->and(ModelHooks::destination($user))->toBeInstanceOf(LinearDestination::class)
        ->and(ModelHooks::connection(User::factory()->create()))->toBeNull()
        ->and(ModelHooks::destination(User::factory()->create()))->toBeNull();
});

test('a source model exposes its issue as attributes', function () {
    $user = User::factory()->create();
    $ticket = Ticket::factory()->for($user)->create();

    expect($ticket->linear_issue_url)->toBeNull()
        ->and($ticket->linear_issue_identifier)->toBeNull()
        ->and($ticket->linear_sync_status)->toBeNull();

    LinearIssueLink::factory()->for($ticket, 'linkable')->for($user, 'owner')->synced()->create();

    $ticket = $ticket->fresh();

    expect($ticket->linear_issue_url)->toBe('https://linear.app/acme/issue/SUP-1')
        ->and($ticket->linear_issue_identifier)->toBe('SUP-1')
        ->and($ticket->linear_sync_status?->value)->toBe('synced')
        ->and($ticket->linearIssueLink->linkable?->is($ticket))->toBeTrue()
        ->and(json_decode($ticket->toJson(), true))->toMatchArray([
            'linear_issue_url' => 'https://linear.app/acme/issue/SUP-1',
            'linear_issue_identifier' => 'SUP-1',
            'linear_sync_status' => 'synced',
        ]);
});
