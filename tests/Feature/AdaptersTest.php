<?php

declare(strict_types=1);

use Dniccum\Linear\Contracts\Connection;
use Dniccum\Linear\Contracts\Mutex;
use Dniccum\Linear\Contracts\SyncQueue;
use Dniccum\Linear\Data\Destination;
use Dniccum\Linear\Data\Issue;
use Dniccum\Linear\Data\IssuePayload;
use Dniccum\Linear\Data\Tokens;
use Dniccum\Linear\Enums\LinearAuthMode;
use Dniccum\Linear\Enums\LinearIssueSource;
use Dniccum\Linear\Enums\LinearSyncStatus;
use Dniccum\Linear\Exceptions\DuplicateIssueLinkException;
use Dniccum\Linear\Facades\Linear;
use Dniccum\Linear\Jobs\CreateLinearIssue;
use Dniccum\Linear\Jobs\DeliverLinearComment;
use Dniccum\Linear\Laravel\Casts\IssuePayloadCast;
use Dniccum\Linear\Laravel\EloquentStore;
use Dniccum\Linear\Laravel\EloquentSync;
use Dniccum\Linear\Laravel\ModelOwner;
use Dniccum\Linear\LinearConfig;
use Dniccum\Linear\LinearServiceProvider;
use Dniccum\Linear\Models\LinearCommentDelivery;
use Dniccum\Linear\Models\LinearConnection;
use Dniccum\Linear\Models\LinearDestination;
use Dniccum\Linear\Models\LinearIssueLink;
use Dniccum\Linear\Services\LinearClient;
use Dniccum\Linear\Testing\InMemory\InMemoryIssueLink;
use Dniccum\Linear\Testing\InMemory\InMemoryOwner;
use Dniccum\Linear\Testing\InMemory\InMemorySource;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\Ticket;
use Workbench\App\Models\User;

/*
|--------------------------------------------------------------------------
| Laravel adapters
|--------------------------------------------------------------------------
|
| The pieces that plug the framework-agnostic core into Laravel.
|
*/

test('the container hands the client the Laravel HTTP client, so Http::fake() applies', function () {
    config(['linear.api_url' => 'https://proxy.test/']);
    Http::fake(['proxy.test/*' => Http::response(['data' => ['viewer' => ['id' => 'u', 'name' => 'Ada', 'email' => 'a@x.test', 'organization' => ['id' => 'o', 'name' => 'Acme', 'urlKey' => 'acme']]]])]);

    app(LinearClient::class)->viewer('token');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://proxy.test/graphql');
});

test('the configuration is read from the Laravel config on every resolution', function () {
    config(['linear.client_id' => 'one', 'linear.client_secret' => 'secret', 'linear.redirect' => null, 'linear.on_update' => 'comment', 'linear.auth_mode' => 'api_key']);

    $first = app(LinearConfig::class);

    config(['linear.client_id' => 'two', 'linear.redirect' => 'https://app.test/cb']);

    $second = app(LinearConfig::class);

    expect($first->clientId)->toBe('one')
        ->and($first->redirectUri)->toBe(route('linear.callback'))
        ->and($first->onUpdate)->toBe('comment')
        ->and($first->authMode)->toBe(LinearAuthMode::ApiKey)
        ->and($second->clientId)->toBe('two')
        ->and($second->redirectUri)->toBe('https://app.test/cb');
});

test('the container binds the cache mutex to the default cache store: a held lock times out the next worker', function () {
    $lock = Cache::lock('linear-test-lock', 30);
    $lock->get();

    try {
        expect(fn () => app(Mutex::class)->synchronized('linear-test-lock', 5, 0, fn () => 'never'))
            ->toThrow(LockTimeoutException::class);
    } finally {
        $lock->release();
    }
});

test('the queue port dispatches the jobs, after commit when asked', function () {
    Queue::fake();
    $link = LinearIssueLink::factory()->for(Ticket::factory()->create(), 'linkable')->for(User::factory()->create(), 'owner')->create();
    $delivery = LinearCommentDelivery::factory()->create(['linear_issue_link_id' => $link->id]);
    $queue = app(SyncQueue::class);

    $queue->issue($link);
    $queue->issue($link, afterCommit: true);
    $queue->comment($delivery);
    $queue->comment($delivery, afterCommit: true);

    Queue::assertPushed(CreateLinearIssue::class, 2);
    Queue::assertPushed(DeliverLinearComment::class, 2);
    Queue::assertPushed(CreateLinearIssue::class, fn (CreateLinearIssue $job) => $job->linearIssueLinkId === $link->id && $job->afterCommit === true);
    Queue::assertPushed(DeliverLinearComment::class, fn (DeliverLinearComment $job) => $job->linearCommentDeliveryId === $delivery->id && $job->afterCommit === null);
});

test('the migrations are published with a timestamp, and publishing again keeps the file names', function () {
    $directory = database_path('migrations');
    $existing = "{$directory}/2020_01_02_030405_create_linear_destinations_table.php";
    File::ensureDirectoryExists($directory);
    File::put($existing, '<?php // published earlier');

    try {
        app()->register(LinearServiceProvider::class, force: true);

        $targets = array_values(ServiceProvider::pathsToPublish(LinearServiceProvider::class, 'linear-migrations'));
        $destinations = array_values(array_filter($targets, fn (string $target): bool => str_contains($target, 'destinations')));
        $others = array_values(array_filter($targets, fn (string $target): bool => ! str_contains($target, 'destinations')));

        expect($targets)->toHaveCount(4)
            ->and(str_replace('\\', '/', $destinations[0]))->toBe(str_replace('\\', '/', $existing))
            ->and($others)->each->toMatch('#/\d{4}_\d{2}_\d{2}_\d{6}_create_linear_\w+_table\.php$#');
    } finally {
        File::delete($existing);
    }
});

test('the issue payload cast stores JSON and rejects anything else', function () {
    $cast = new IssuePayloadCast;
    $model = new LinearIssueLink;
    $payload = new IssuePayload(new Destination('team-1', priority: 2), 'Title', 'Body');

    $stored = $cast->set($model, 'payload', $payload, []);

    expect($cast->get($model, 'payload', $stored['payload'], []))->toEqual($payload)
        ->and($cast->get($model, 'payload', null, []))->toBeNull()
        ->and(json_decode($cast->set($model, 'payload', ['team_id' => 'team-2'], [])['payload'], true))->toMatchArray(['team_id' => 'team-2', 'label_ids' => []])
        ->and(fn () => $cast->set($model, 'payload', 'nope', []))->toThrow(InvalidArgumentException::class, 'must be an IssuePayload');
});

test('the Eloquent store only handles Eloquent links, and reports a lost race', function () {
    $store = app(EloquentStore::class);
    $foreign = new InMemoryIssueLink(1, new InMemorySource(1, null), new InMemoryOwner(1), 'org-1', LinearIssueSource::Automatic, 'issue', new IssuePayload(new Destination('t')));
    $user = connectedUser();
    $ticket = Ticket::factory()->for($user)->create();
    $source = app(EloquentSync::class)->source($ticket);
    $owner = new ModelOwner($user);
    $connection = $owner->connection();

    expect(fn () => $store->sourceFor($foreign))->toThrow(InvalidArgumentException::class, 'only handles')
        ->and($store->linkFor($source))->toBeNull()
        ->and($store->findLink(999))->toBeNull()
        ->and($store->findDelivery(999))->toBeNull();

    $link = $store->createLink($source, $owner, $connection, LinearIssueSource::Manual, 'issue-1', new IssuePayload(new Destination('team-1')));

    expect($store->linkFor($source)?->linkId())->toBe($link->linkId())
        ->and($store->findLink($link->linkId())?->linkId())->toBe($link->linkId())
        ->and($store->sourceFor($link)?->sourceType())->toBe($ticket->getMorphClass())
        ->and($store->connectionFor($link)?->connectionId())->toBe($connection->connectionId())
        ->and(fn () => $store->createLink($source, $owner, $connection, LinearIssueSource::Manual, 'issue-2', new IssuePayload(new Destination('team-1'))))
        ->toThrow(DuplicateIssueLinkException::class);
});

test('the model wrappers expose the identity and content the core needs', function () {
    $user = connectedUser();
    $ticket = Ticket::factory()->for($user)->create(['title' => 'Cannot upload']);
    $source = app(EloquentSync::class)->source($ticket);

    expect($source->sourceType())->toBe($ticket->getMorphClass())
        ->and($source->sourceId())->toBe($ticket->getKey())
        ->and($source->title())->toBe('Cannot upload')
        ->and($source->owner()?->ownerId())->toBe($user->getKey())
        ->and($source->owner()?->ownerType())->toBe($user->getMorphClass())
        ->and($source->owner()?->destination())->toBeNull()
        ->and($source->destinationOverride())->toBeNull()
        ->and($source->description())->toContain('Cannot upload')
        ->and($source->comment('deleted'))->toContain('was deleted')
        ->and($source->changes())->toBe([])
        ->and((new ModelOwner(new User))->ownerId())->toBe('');

    $ticket->update(['title' => 'Changed']);

    expect($source->changes())->toBe(['title' => 'Changed']);
});

test('the Eloquent facade over the sync takes and returns models', function () {
    $this->fake = Linear::fake();
    $user = connectedUser();
    LinearDestination::factory()->for($user, 'owner')->create();
    $ticket = Ticket::factory()->for($user)->create();
    $sync = app(EloquentSync::class);

    expect($sync->linkFor($ticket))->toBeInstanceOf(LinearIssueLink::class);

    $sync->retry($sync->linkFor($ticket));

    expect($sync->comment($ticket, 'hello', $ticket))->toBeInstanceOf(LinearCommentDelivery::class)
        ->and($sync->fileAutomatically($ticket))->toBeInstanceOf(LinearIssueLink::class);
});

test('the Eloquent models implement the core contracts', function () {
    $user = connectedUser(['refresh_token' => 'refresh-1', 'access_token' => 'access-1']);
    $connection = $user->linearConnection;

    expect($connection)->toBeInstanceOf(Connection::class)
        ->and($connection->connectionId())->toBe($connection->id)
        ->and($connection->organizationId())->toBe($connection->linear_organization_id)
        ->and($connection->authMode())->toBe(LinearAuthMode::OAuth)
        ->and($connection->accessToken())->toBe('access-1')
        ->and($connection->refreshToken())->toBe('refresh-1');

    LinearConnection::query()->whereKey($connection->id)->update(['organization_name' => 'Changed']);
    $connection->reload();

    expect($connection->organization_name)->toBe('Changed');

    $connection->storeTokens(new Tokens('access-2', null, 3600, []));

    expect($connection->fresh())
        ->access_token->toBe('access-2')
        ->refresh_token->toBe('refresh-1')
        ->and($connection->fresh()->token_expires_at?->isFuture())->toBeTrue();

    $link = LinearIssueLink::factory()->for(Ticket::factory()->create(), 'linkable')->for($user, 'owner')->failed()->create();

    expect($link->issueUrl())->toBeNull()
        ->and($link->issueIdentifier())->toBeNull();

    $link->markSynced($connection, new Issue($link->issueId(), 'SUP-9', 'https://linear.app/i/SUP-9'));

    expect($link->fresh())
        ->linear_issue_identifier->toBe('SUP-9')
        ->and($link->issueUrl())->toBe('https://linear.app/i/SUP-9')
        ->and($link->syncStatus())->toBe(LinearSyncStatus::Synced);
});

test('restarting a link can keep or replace its issue id', function () {
    $user = connectedUser();
    $link = LinearIssueLink::factory()->for(Ticket::factory()->create(), 'linkable')->for($user, 'owner')->failed()->create(['attempts' => 3]);
    $owner = new ModelOwner($user);
    $payload = new IssuePayload(new Destination('team-2'), 'T', 'D');
    $issueId = $link->issueId();

    $link->restart($owner, $user->linearConnection, LinearIssueSource::Manual, $payload);

    expect($link->fresh())->status->toBe(LinearSyncStatus::Pending)->linear_issue_id->toBe($issueId)->attempts->toBe(3)->last_error->toBeNull();

    $link->restart($owner, $user->linearConnection, LinearIssueSource::Manual, $payload, 'new-issue-id');

    expect($link->fresh())->linear_issue_id->toBe('new-issue-id')->attempts->toBe(0)->source->toBe(LinearIssueSource::Manual);
});

test('the job helpers delegate to the sync', function () {
    expect(CreateLinearIssue::describe(new RuntimeException('secret')))->toBe('Something went wrong while syncing with Linear. Try again shortly.')
        ->and(CreateLinearIssue::describe(null))->toBe('Something went wrong while syncing with Linear. Try again shortly.');
});

test('a Laravel app gets the core config object with the callback route filled in', function () {
    config(['linear.client_id' => 'id', 'linear.client_secret' => 'secret', 'linear.redirect' => null]);

    expect(app(LinearConfig::class)->hasOAuthCredentials())->toBeTrue();
});
