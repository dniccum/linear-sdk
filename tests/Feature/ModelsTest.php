<?php

declare(strict_types=1);

use Dniccum\Linear\Data\Destination;
use Dniccum\Linear\Data\IssuePayload;
use Dniccum\Linear\Enums\LinearAuthMode;
use Dniccum\Linear\Enums\LinearConnectionStatus;
use Dniccum\Linear\Enums\LinearIssueSource;
use Dniccum\Linear\Enums\LinearSendMode;
use Dniccum\Linear\Enums\LinearSyncStatus;
use Dniccum\Linear\Models\LinearCommentDelivery;
use Dniccum\Linear\Models\LinearConnection;
use Dniccum\Linear\Models\LinearDestination;
use Dniccum\Linear\Models\LinearIssueLink;
use Dniccum\Linear\Tests\Fixtures\PlainModel;
use Illuminate\Support\Facades\DB;
use Workbench\App\Models\Ticket;
use Workbench\App\Models\User;

test('the factories build every model with their defaults', function () {
    $user = User::factory()->create();

    $connection = LinearConnection::factory()->for($user, 'owner')->create();
    $destination = LinearDestination::factory()->for($user, 'owner')->create();
    $link = LinearIssueLink::factory()->for(Ticket::factory()->create(), 'linkable')->for($user, 'owner')->create();
    $delivery = LinearCommentDelivery::factory()->create(['linear_issue_link_id' => $link->id]);

    expect($connection)
        ->auth_type->toBe(LinearAuthMode::OAuth)
        ->status->toBe(LinearConnectionStatus::Active)
        ->scopes->toBe(['read', 'issues:create', 'comments:create'])
        ->and($destination)->send_mode->toBe(LinearSendMode::Automatic)->team_id->toBe('team-1')
        ->and($link)->status->toBe(LinearSyncStatus::Pending)->source->toBe(LinearIssueSource::Automatic)->attempts->toBe(0)
        ->and($delivery)->status->toBe(LinearSyncStatus::Pending)->attempts->toBe(0);
});

test('the factory states describe common situations', function () {
    $user = User::factory()->create();

    $apiKey = LinearConnection::factory()->for($user, 'owner')->apiKey('lin_api_x')->make();
    $stale = LinearConnection::factory()->for($user, 'owner')->needsReconnect()->make();
    $manual = LinearDestination::factory()->for($user, 'owner')->manual()->make();
    $synced = LinearIssueLink::factory()->synced()->make();
    $failed = LinearIssueLink::factory()->failed('Boom')->make();

    expect($apiKey)->auth_type->toBe(LinearAuthMode::ApiKey)->refresh_token->toBeNull()->token_expires_at->toBeNull()->scopes->toBeNull()
        ->and($apiKey->usesApiKey())->toBeTrue()
        ->and($stale)->status->toBe(LinearConnectionStatus::NeedsReconnect)->last_error->not->toBeNull()
        ->and($stale->isActive())->toBeFalse()
        ->and($manual->send_mode)->toBe(LinearSendMode::Manual)
        ->and($synced)->status->toBe(LinearSyncStatus::Synced)->linear_issue_identifier->toBe('SUP-1')
        ->and($failed)->status->toBe(LinearSyncStatus::Failed)->last_error->toBe('Boom');
});

test('without an explicit owner the factories build the configured owner model', function () {
    $connection = LinearConnection::factory()->create();
    $destination = LinearDestination::factory()->create();

    expect($connection->owner)->toBeInstanceOf(User::class)
        ->and($destination->owner)->toBeInstanceOf(User::class)
        ->and(User::count())->toBe(2);
});

test('an owner model without a factory is explained', function () {
    config(['linear.owner_model' => PlainModel::class]);

    expect(fn () => LinearConnection::factory()->create())->toThrow(LogicException::class, 'Pass an owner')
        ->and(fn () => LinearDestination::factory()->create())->toThrow(LogicException::class, 'Pass an owner');
});

test('tokens are encrypted at rest and hidden from serialization', function () {
    $connection = LinearConnection::factory()->for(User::factory()->create(), 'owner')->create(['access_token' => 'secret-a', 'refresh_token' => 'secret-r']);

    $raw = DB::table('linear_connections')->first();

    expect($raw->access_token)->not->toContain('secret-a')
        ->and($raw->refresh_token)->not->toContain('secret-r')
        ->and($connection->fresh()->access_token)->toBe('secret-a')
        ->and(json_encode($connection))->not->toContain('secret');
});

test('a token close to expiry is considered expiring', function () {
    $connection = new LinearConnection;

    expect($connection->tokenExpiresSoon())->toBeFalse();

    $connection->token_expires_at = now()->addMinutes(10);
    expect($connection->tokenExpiresSoon())->toBeFalse();

    $connection->token_expires_at = now()->addMinutes(4);
    expect($connection->tokenExpiresSoon())->toBeTrue();
});

test('a connection tracks its health', function () {
    $connection = LinearConnection::factory()->for(User::factory()->create(), 'owner')->create();

    $connection->markFailed('Something broke');

    expect($connection->fresh())->last_error->toBe('Something broke')->last_error_at->not->toBeNull()->status->toBe(LinearConnectionStatus::Active);

    $connection->markNeedsReconnect('Reconnect');

    expect($connection->fresh()->isActive())->toBeFalse();

    $connection->markSynced();

    expect($connection->fresh())->last_error->toBeNull()->last_error_at->toBeNull()->last_synced_at->not->toBeNull();
});

test('a destination exposes its settings as a DTO', function () {
    $destination = new LinearDestination([
        'team_id' => 'team-1',
        'project_id' => 'project-1',
        'label_ids' => ['label-1'],
        'priority' => 3,
        'assignee_id' => 'user-1',
    ]);

    expect($destination->destination())->toEqual(new Destination('team-1', 'project-1', null, ['label-1'], 3, 'user-1'))
        ->and((new LinearDestination(['team_id' => 'team-2']))->destination()->labelIds)->toBe([]);
});

test('the issue payload column round-trips as a DTO', function () {
    $user = User::factory()->create();
    $link = LinearIssueLink::factory()->for(Ticket::factory()->create(), 'linkable')->for($user, 'owner')->create([
        'payload' => new IssuePayload(new Destination(teamId: 'team-1', labelIds: ['label-1']), title: 'Help', description: 'Body'),
    ]);

    $stored = json_decode(DB::table('linear_issue_links')->where('id', $link->id)->value('payload'), true);

    expect($stored)->toMatchArray(['team_id' => 'team-1', 'label_ids' => ['label-1'], 'title' => 'Help', 'description' => 'Body'])
        ->and($link->fresh()->payload)
        ->toBeInstanceOf(IssuePayload::class)
        ->title->toBe('Help')
        ->destination->labelIds->toBe(['label-1']);
});

test('the issue payload column accepts arrays and refuses anything else', function () {
    $link = new LinearIssueLink;
    $link->payload = ['team_id' => 'team-1', 'title' => 'T'];

    expect($link->payload)->toBeInstanceOf(IssuePayload::class)->title->toBe('T');

    expect(function () use ($link) {
        $link->payload = 'nonsense';
    })->toThrow(InvalidArgumentException::class);

    $link->setRawAttributes(['payload' => null]);

    expect($link->payload)->toBeNull();
});
