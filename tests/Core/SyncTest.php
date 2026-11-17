<?php

declare(strict_types=1);

use Dniccum\Linear\Contracts\ErrorReporter;
use Dniccum\Linear\Contracts\IssueLink;
use Dniccum\Linear\Data\Destination;
use Dniccum\Linear\Enums\LinearSyncStatus;
use Dniccum\Linear\Events\LinearCommentDelivered;
use Dniccum\Linear\Events\LinearIssueCreated;
use Dniccum\Linear\Events\LinearIssueFailed;
use Dniccum\Linear\Exceptions\LinearApiException;
use Dniccum\Linear\LinearConfig;
use Dniccum\Linear\Services\LinearIssueSync;
use Dniccum\Linear\Testing\InMemory\InMemoryConnection;
use Dniccum\Linear\Testing\InMemory\InMemoryDestination;
use Dniccum\Linear\Testing\InMemory\InMemoryOwner;
use Dniccum\Linear\Testing\InMemory\InMemorySource;

/*
|--------------------------------------------------------------------------
| The sync, with no framework
|--------------------------------------------------------------------------
|
| Everything below runs on the in-memory store and queue and the fake client:
| proof that the issue workflow does not need Laravel (or a database).
|
*/

test('a created record is filed once, by a worker, and announced', function () {
    $app = coreSync();
    $source = coreSource($app->owner);

    $app->sync->handleEvent($source, 'created');
    $app->sync->handleEvent($source, 'created');

    expect($app->store->links)->toHaveCount(1)
        ->and($app->queue->messages)->toHaveCount(1)
        ->and($app->store->links[1]->syncStatus())->toBe(LinearSyncStatus::Pending);

    $app->queue->work($app->sync);

    $link = $app->store->links[1];

    expect($link->isSynced())->toBeTrue()
        ->and($link->issueIdentifier())->toBe('FAKE-1')
        ->and($link->issueUrl())->toBe('https://linear.app/fake/issue/FAKE-1')
        ->and($link->issuePayload()->title)->toBe('Record 1')
        ->and($link->issuePayload()->description)->toBe('It broke.')
        ->and($app->client->createdIssues())->toHaveCount(1)
        ->and($app->connection->synced)->toBeTrue()
        ->and($app->events[0])->toBeInstanceOf(LinearIssueCreated::class);
});

test('records without an owner, destination or healthy connection are not filed', function () {
    $app = coreSync();

    $app->sync->handleEvent(new InMemorySource(1, null), 'created');

    $withoutDestination = coreSync(withDestination: false);
    $withoutDestination->sync->handleEvent(coreSource($withoutDestination->owner), 'created');

    $app->connection->active = false;
    $app->sync->handleEvent(coreSource($app->owner, 2), 'created');

    $app->owner->connection = null;
    $app->sync->handleEvent(coreSource($app->owner, 3), 'created');

    $manual = coreSync();
    $manual->owner->destination = new InMemoryDestination(new Destination('team-1'), automatic: false);
    $manual->sync->handleEvent(coreSource($manual->owner), 'created');

    expect($app->store->links)->toBe([])
        ->and($withoutDestination->store->links)->toBe([])
        ->and($manual->store->links)->toBe([])
        ->and($app->queue->messages)->toBe([]);
});

test('a record can route itself somewhere else', function () {
    $app = coreSync();
    $source = coreSource($app->owner);
    $source->destinationOverride = new InMemoryDestination(new Destination('team-9'));

    $app->sync->fileAutomatically($source);
    $app->queue->work($app->sync);

    expect($app->client->createdIssues()[0]['payload']->destination->teamId)->toBe('team-9');
});

test('unknown events are ignored', function () {
    $app = coreSync();

    $app->sync->handleEvent(coreSource($app->owner), 'restored');

    expect($app->store->links)->toBe([]);
});

test('a manual send uses the owner destination plus the overrides', function () {
    $app = coreSync();
    $source = coreSource($app->owner);

    $link = $app->sync->sendManually($source, ['title' => 'Custom', 'priority' => 2, 'ignored' => 'x']);
    $app->queue->work($app->sync);

    $payload = $app->client->createdIssues()[0]['payload'];

    expect($link->isSynced())->toBeTrue()
        ->and($payload->title)->toBe('Custom')
        ->and($payload->description)->toBe('It broke.')
        ->and($payload->destination->teamId)->toBe('team-1')
        ->and($payload->destination->priority)->toBe(2);
});

test('a manual send is refused when it cannot work', function () {
    $app = coreSync();
    $source = coreSource($app->owner);

    expect(fn () => $app->sync->sendManually(new InMemorySource(1, null)))->toThrow(LinearApiException::class, 'no Linear owner')
        ->and(fn () => $app->sync->sendManually(coreSource(new InMemoryOwner(1))))->toThrow(LinearApiException::class, 'not connected');

    $app->connection->active = false;
    expect(fn () => $app->sync->sendManually($source))->toThrow(LinearApiException::class, 'reauthorized');
    $app->connection->active = true;

    $app->owner->destination = null;
    expect(fn () => $app->sync->sendManually($source))->toThrow(LinearApiException::class, 'No Linear team');
    expect($app->sync->sendManually($source, ['team_id' => 'team-3'])->isSynced())->toBeFalse();

    expect(fn () => $app->sync->sendManually($source))->toThrow(LinearApiException::class, 'already being created');

    $app->queue->work($app->sync);

    expect(fn () => $app->sync->sendManually($source))->toThrow(LinearApiException::class, 'already linked to FAKE-1');
});

test('a transient failure is retried after the backoff and still files one issue', function () {
    $app = coreSync();
    $app->client->failWith('createIssue', new LinearApiException('Down', LinearApiException::TRANSIENT));

    $app->sync->handleEvent(coreSource($app->owner), 'created');
    $delays = [];
    $app->queue->work($app->sync, $delays);

    expect($delays)->toBe([30])
        ->and($app->store->links[1]->isSynced())->toBeTrue()
        ->and($app->store->links[1]->attemptCount())->toBe(2)
        ->and($app->client->createdIssues())->toHaveCount(1);
});

test('a permanent failure fails the link and can be retried by hand', function () {
    $app = coreSync();
    $app->client->failWith('createIssue', new LinearApiException('No access', LinearApiException::FORBIDDEN));
    $source = coreSource($app->owner);

    $app->sync->handleEvent($source, 'created');
    $delays = [];
    $app->queue->work($app->sync, $delays);

    $link = $app->store->links[1];

    expect($delays)->toBe([])
        ->and($link->syncStatus())->toBe(LinearSyncStatus::Failed)
        ->and($link->lastError)->toBe('No access')
        ->and($app->connection->lastError)->toBe('No access')
        ->and($app->events[count($app->events) - 1])->toBeInstanceOf(LinearIssueFailed::class);

    // A manual send reuses the failed link and starts over.
    $again = $app->sync->sendManually($source, ['title' => 'Second try']);
    $app->queue->work($app->sync);

    expect($again)->toBe($link)
        ->and($link->isSynced())->toBeTrue()
        ->and($link->issuePayload()->title)->toBe('Second try');
});

test('a failed link is requeued by retry()', function () {
    $app = coreSync();
    $app->client->failWith('createIssue', new LinearApiException('No access', LinearApiException::FORBIDDEN));

    $app->sync->handleEvent(coreSource($app->owner), 'created');
    $app->queue->work($app->sync);
    $link = $app->store->links[1];

    $app->sync->retry($link);

    expect($link->syncStatus())->toBe(LinearSyncStatus::Pending)->and($link->lastError)->toBeNull();

    $app->sync->retry($link);
    $app->queue->work($app->sync);

    expect($link->isSynced())->toBeTrue();

    // Nothing left to retry.
    $app->sync->retry($link);

    expect($app->queue->messages)->toBe([]);
});

test('a retry after the issue was created but the response was lost adopts it', function () {
    $app = coreSync();
    $app->client->failWith('createIssue', new LinearApiException('No access', LinearApiException::FORBIDDEN));
    $app->sync->handleEvent(coreSource($app->owner), 'created');
    $app->queue->work($app->sync);
    $link = $app->store->links[1];

    // Linear did create it after all.
    $app->client->createIssue($app->connection, $link->issueId(), $link->issuePayload());
    $source = coreSource($app->owner);

    $adopted = $app->sync->sendManually($source, ['title' => 'Ignored']);

    expect($adopted)->toBe($link)
        ->and($link->isSynced())->toBeTrue()
        ->and($app->client->createdIssues())->toHaveCount(1)
        ->and($link->issuePayload()->title)->toBe('Record 1');
});

test('filing into another workspace starts the failed link over with a new issue id', function () {
    $app = coreSync();
    $app->client->failWith('createIssue', new LinearApiException('No access', LinearApiException::FORBIDDEN));
    $source = coreSource($app->owner);
    $app->sync->handleEvent($source, 'created');
    $app->queue->work($app->sync);
    $link = $app->store->links[1];
    $oldId = $link->issueId();

    $app->owner->connection = new InMemoryConnection(2, 'org-2');
    $app->sync->sendManually($source);

    expect($link->issueId())->not->toBe($oldId)
        ->and($link->attemptCount())->toBe(0)
        ->and($link->organizationId())->toBe('org-2');
});

test('work is refused when the owner moved to another workspace or disconnected', function () {
    $app = coreSync();
    $app->sync->handleEvent(coreSource($app->owner), 'created');
    $link = $app->store->links[1];

    $app->owner->connection = new InMemoryConnection(2, 'org-2');
    expect(fn () => $app->sync->pushIssue($link))->toThrow(LinearApiException::class, 'not the one this issue was filed in');

    $app->owner->connection = null;
    expect(fn () => $app->sync->pushIssue($link))->toThrow(LinearApiException::class, 'not connected');
});

test('comments wait for the issue, are delivered once per origin and once posted are not repeated', function () {
    $app = coreSync();
    $source = coreSource($app->owner);
    $reply = new InMemorySource(5, null, type: 'reply');

    expect($app->sync->comment($source, 'too early'))->toBeNull();

    $app->sync->handleEvent($source, 'created');

    $first = $app->sync->comment($source, 'first', $reply);
    $second = $app->sync->comment($source, 'again', $reply);
    $adhoc = $app->sync->comment($source, 'ad hoc');

    expect($second)->toBe($first)
        ->and($first->wasJustQueued())->toBeFalse()
        ->and($adhoc->wasJustQueued())->toBeTrue()
        ->and(array_column($app->queue->messages, 'type'))->toBe(['issue']);

    $app->queue->work($app->sync);

    expect($first->syncStatus())->toBe(LinearSyncStatus::Synced)
        ->and($adhoc->syncStatus())->toBe(LinearSyncStatus::Synced)
        ->and(array_column($app->client->postedComments(), 'body'))->toBe(['first', 'ad hoc'])
        ->and(collect($app->events)->whereInstanceOf(LinearCommentDelivered::class))->toHaveCount(2);

    // Once the issue exists a new comment is queued straight away.
    $late = $app->sync->comment($source, 'late');
    expect($app->queue->messages)->toHaveCount(1);
    $app->queue->work($app->sync);
    expect($late->syncStatus())->toBe(LinearSyncStatus::Synced);

    // Pushing a delivered comment again is a no-op.
    $app->sync->pushComment($late);

    expect($app->client->postedComments())->toHaveCount(3);
});

test('a comment whose first attempt was lost in flight is adopted, not posted twice', function () {
    $app = coreSync();
    $source = coreSource($app->owner);
    $app->sync->handleEvent($source, 'created');
    $app->queue->work($app->sync);

    $delivery = $app->sync->comment($source, 'hello');
    $app->queue->messages = [];
    $delivery->recordAttempt();
    $app->client->createComment($app->connection, $delivery->commentId(), $app->store->links[1]->issueId(), 'hello');

    $app->sync->pushComment($delivery);

    expect($delivery->syncStatus())->toBe(LinearSyncStatus::Synced)
        ->and($app->client->postedComments())->toHaveCount(1);
});

test('failed comments are requeued by retry() once the issue exists', function () {
    $app = coreSync();
    $source = coreSource($app->owner);
    $app->sync->handleEvent($source, 'created');
    $app->queue->work($app->sync);

    $app->client->failWith('createComment', new LinearApiException('Nope', LinearApiException::FORBIDDEN));
    $delivery = $app->sync->comment($source, 'hello');
    $app->queue->work($app->sync);

    expect($delivery->syncStatus())->toBe(LinearSyncStatus::Failed);

    $app->sync->retry($app->store->links[1]);
    $app->queue->work($app->sync);

    expect($delivery->syncStatus())->toBe(LinearSyncStatus::Synced);
});

test('comments that failed with their issue go out after the issue is retried', function () {
    $app = coreSync();
    $app->client->failWith('createIssue', new LinearApiException('No', LinearApiException::FORBIDDEN));
    $source = coreSource($app->owner);
    $app->sync->handleEvent($source, 'created');
    $delivery = $app->sync->comment($source, 'waiting');
    $app->queue->work($app->sync);
    $delivery->markFailed('failed with the issue');

    $app->sync->retry($app->store->links[1]);
    $app->queue->work($app->sync);

    expect($delivery->syncStatus())->toBe(LinearSyncStatus::Synced);
});

test('updates and deletes comment only when configured to', function () {
    $quiet = coreSync();
    $loud = coreSync(onUpdate: 'comment', onDelete: 'comment');

    foreach ([$quiet, $loud] as $app) {
        $source = coreSource($app->owner);
        $source->changes = ['title' => 'New'];
        $app->sync->handleEvent($source, 'created');
        $app->queue->work($app->sync);
        $app->sync->handleEvent($source, 'updated');
        $source->changes = [];
        $app->sync->handleEvent($source, 'updated');
        $app->sync->handleEvent($source, 'deleted');
        $app->queue->work($app->sync);
    }

    expect($quiet->client->postedComments())->toBe([])
        ->and(array_column($loud->client->postedComments(), 'body'))->toBe(['Record 1 was updated.', 'Record 1 was deleted.']);
});

test('deleting a record that was never filed does nothing, and updating one files it', function () {
    $app = coreSync(onDelete: 'comment');
    $source = coreSource($app->owner);

    $app->sync->handleEvent($source, 'deleted');
    expect($app->store->links)->toBe([]);

    $app->sync->handleEvent($source, 'updated');
    expect($app->store->links)->toHaveCount(1);
});

test('workers skip work that is gone or no longer pending', function () {
    $app = coreSync();
    $app->sync->handleEvent(coreSource($app->owner), 'created');
    $app->queue->work($app->sync);
    $delivery = $app->sync->comment(coreSource($app->owner), 'x');
    $app->queue->work($app->sync);

    expect($app->sync->processIssue(99))->toBeNull()
        ->and($app->sync->processIssue(1))->toBeNull()
        ->and($app->sync->processComment(99))->toBeNull()
        ->and($app->sync->processComment($delivery->deliveryId()))->toBeNull();
});

test('giving up marks the work failed unless it made it after all', function () {
    $app = coreSync();
    $source = coreSource($app->owner);
    $app->sync->handleEvent($source, 'created');
    $link = $app->store->links[1];
    $delivery = $app->sync->comment($source, 'x');

    $app->sync->failIssue($link->linkId(), new RuntimeException('boom'));
    $app->sync->failIssue(99);
    $app->sync->failComment($delivery->deliveryId());
    $app->sync->failComment(99);

    expect($link->syncStatus())->toBe(LinearSyncStatus::Failed)
        ->and($link->lastError)->toBe('Something went wrong while syncing with Linear. Try again shortly.')
        ->and($delivery->syncStatus())->toBe(LinearSyncStatus::Failed)
        ->and($app->sync->processIssue($link->linkId()))->toBeNull();

    $app->sync->retry($link);
    $app->queue->work($app->sync);
    $app->sync->failIssue($link->linkId());
    $app->sync->failComment($delivery->deliveryId());

    expect($link->isSynced())->toBeTrue()
        ->and($delivery->syncStatus())->toBe(LinearSyncStatus::Synced);
});

test('unexpected exceptions are reported, treated as transient, and exhaust the attempts', function () {
    $reported = [];
    $reporter = new class($reported) implements ErrorReporter
    {
        /** @param list<Throwable> $reported */
        public function __construct(public array &$reported) {}

        public function report(Throwable $e): void
        {
            $this->reported[] = $e;
        }
    };

    $app = coreSync();
    $sync = new class($app->client, $app->store, $app->queue, new LinearConfig, null, $reporter) extends LinearIssueSync
    {
        public function pushIssue(IssueLink $link): void
        {
            throw new RuntimeException('kaboom');
        }
    };
    $app->sync->handleEvent(coreSource($app->owner), 'created');
    $delays = [];
    $app->queue->work($sync, $delays);

    expect($delays)->toBe([30, 120, 600, 1800])
        ->and($reported)->toHaveCount(5)
        ->and($app->store->links[1]->syncStatus())->toBe(LinearSyncStatus::Failed)
        ->and($sync->isRetryable(null))->toBeTrue()
        ->and($sync->isRetryable(new LinearApiException('x', LinearApiException::FORBIDDEN)))->toBeFalse()
        ->and(LinearIssueSync::backoff(1))->toBe(30)
        ->and(LinearIssueSync::backoff(9))->toBe(1800)
        ->and(LinearIssueSync::describe(new LinearApiException('Specific', LinearApiException::FORBIDDEN)))->toBe('Specific');
});

test('a comment that hits a transient failure is retried and posted once', function () {
    $app = coreSync();
    $source = coreSource($app->owner);
    $app->sync->handleEvent($source, 'created');
    $app->queue->work($app->sync);

    $app->client->failWith('createComment', new LinearApiException('Down', LinearApiException::TRANSIENT));
    $delivery = $app->sync->comment($source, 'hello');
    $delays = [];
    $app->queue->work($app->sync, $delays);

    expect($delays)->toBe([30])
        ->and($delivery->syncStatus())->toBe(LinearSyncStatus::Synced)
        ->and($delivery->attemptCount())->toBe(2)
        ->and($app->client->postedComments())->toHaveCount(1);
});

test('the in-memory owner and source identify themselves', function () {
    $app = coreSync();

    expect([$app->owner->ownerType(), $app->owner->ownerId()])->toBe(['owner', 7])
        ->and([coreSource($app->owner, 3)->sourceType(), coreSource($app->owner, 3)->sourceId()])->toBe(['record', 3]);
});
