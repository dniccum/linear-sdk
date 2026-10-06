<?php

declare(strict_types=1);

use Dniccum\Linear\Enums\LinearSyncStatus;
use Dniccum\Linear\Exceptions\LinearApiException;
use Dniccum\Linear\Jobs\CreateLinearIssue;
use Dniccum\Linear\Jobs\DeliverLinearComment;
use Dniccum\Linear\Models\LinearCommentDelivery;
use Dniccum\Linear\Models\LinearConnection;
use Dniccum\Linear\Models\LinearDestination;
use Dniccum\Linear\Models\LinearIssueLink;
use Dniccum\Linear\Services\IssueComposer;
use Dniccum\Linear\Services\LinearClient;
use Dniccum\Linear\Services\LinearIssueSync;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Workbench\App\Models\Ticket;
use Workbench\App\Models\User;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->ticket = Ticket::factory()->for($this->owner)->create();
    LinearConnection::factory()->for($this->owner, 'owner')->create();
});

test('the jobs retry five times with a growing backoff', function () {
    expect((new CreateLinearIssue(1))->tries)->toBe(5)
        ->and((new CreateLinearIssue(1))->backoff)->toBe([30, 120, 600, 1800])
        ->and((new DeliverLinearComment(1))->tries)->toBe(5)
        ->and((new DeliverLinearComment(1))->backoff)->toBe([30, 120, 600, 1800]);
});

test('the jobs use the configured queue connection and name', function () {
    expect((new CreateLinearIssue(1))->connection)->toBeNull()
        ->and((new CreateLinearIssue(1))->queue)->toBeNull();

    config(['linear.queue.connection' => 'redis', 'linear.queue.name' => 'linear']);

    foreach ([new CreateLinearIssue(1), new DeliverLinearComment(1)] as $job) {
        expect($job->connection)->toBe('redis')->and($job->queue)->toBe('linear');
    }
});

test('dispatching goes onto the configured queue after commit', function () {
    Queue::fake();
    config(['linear.queue.name' => 'linear']);

    LinearDestination::factory()->for($this->owner, 'owner')->create();

    Ticket::factory()->for($this->owner)->create();

    Queue::assertPushedOn('linear', CreateLinearIssue::class, fn (CreateLinearIssue $job) => $job->afterCommit === true);
});

test('a retryable failure releases the job with the matching backoff', function (int $attempt, int $delay) {
    fakeLinearApi(['CreateIssue' => Http::response(['errors' => [['message' => 'Down']]], 503)]);
    $link = LinearIssueLink::factory()->for($this->ticket, 'linkable')->for($this->owner, 'owner')->create();

    $job = (new CreateLinearIssue($link->id))->withFakeQueueInteractions();
    $job->job->attempts = $attempt;
    $job->handle(app(LinearIssueSync::class));

    $job->assertReleased($delay);
    expect($link->fresh())->status->toBe(LinearSyncStatus::Pending)->last_error->toContain('temporarily unavailable');
})->with([
    'first attempt' => [1, 30],
    'second attempt' => [2, 120],
    'third attempt' => [3, 600],
    'fourth attempt' => [4, 1800],
]);

test('the last attempt fails the link for good', function () {
    fakeLinearApi(['CreateIssue' => Http::response(['errors' => [['message' => 'Down']]], 503)]);
    $link = LinearIssueLink::factory()->for($this->ticket, 'linkable')->for($this->owner, 'owner')->create();

    $job = (new CreateLinearIssue($link->id))->withFakeQueueInteractions();
    $job->job->attempts = 5;
    $job->handle(app(LinearIssueSync::class));

    $job->assertNotReleased();
    expect($link->fresh()->status)->toBe(LinearSyncStatus::Failed);
});

test('a permanent failure is not released', function () {
    fakeLinearApi(['CreateIssue' => Http::response(['errors' => [['message' => 'Bad', 'extensions' => ['code' => 'FORBIDDEN']]]], 400)]);
    $link = LinearIssueLink::factory()->for($this->ticket, 'linkable')->for($this->owner, 'owner')->create();

    $job = (new CreateLinearIssue($link->id))->withFakeQueueInteractions();
    $job->handle(app(LinearIssueSync::class));

    $job->assertNotReleased();
    expect($link->fresh()->status)->toBe(LinearSyncStatus::Failed);
});

test('an unexpected exception is reported and treated as transient', function () {
    Exceptions::fake();
    app()->bind(LinearIssueSync::class, fn () => new class(app(LinearClient::class), app(IssueComposer::class)) extends LinearIssueSync
    {
        public function pushIssue(LinearIssueLink $link): void
        {
            throw new RuntimeException('kaboom');
        }
    });
    $link = LinearIssueLink::factory()->for($this->ticket, 'linkable')->for($this->owner, 'owner')->create();

    $job = (new CreateLinearIssue($link->id))->withFakeQueueInteractions();
    $job->handle(app(LinearIssueSync::class));

    $job->assertReleased(30);
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'kaboom');
    expect($link->fresh()->last_error)->toBe('Something went wrong while syncing with Linear. Try again shortly.');
});

test('only pending work is sent', function () {
    fakeLinearApi();
    $failed = LinearIssueLink::factory()->for($this->ticket, 'linkable')->for($this->owner, 'owner')->failed()->create();

    (new CreateLinearIssue($failed->id))->handle(app(LinearIssueSync::class));
    (new CreateLinearIssue(9999))->handle(app(LinearIssueSync::class));

    $delivery = LinearCommentDelivery::factory()->for($failed, 'issueLink')->create(['status' => LinearSyncStatus::Failed]);

    (new DeliverLinearComment($delivery->id))->handle(app(LinearIssueSync::class));
    (new DeliverLinearComment(9999))->handle(app(LinearIssueSync::class));

    Http::assertNothingSent();
});

test('a comment job releases on a retryable failure and fails for good otherwise', function () {
    fakeLinearApi(['CreateComment' => Http::response(['errors' => [['message' => 'Down']]], 503)]);
    $link = LinearIssueLink::factory()->for($this->ticket, 'linkable')->for($this->owner, 'owner')->synced()->create();
    $delivery = LinearCommentDelivery::factory()->for($link, 'issueLink')->create();

    $job = (new DeliverLinearComment($delivery->id))->withFakeQueueInteractions();
    $job->handle(app(LinearIssueSync::class));

    $job->assertReleased(30);
    expect($delivery->fresh()->status)->toBe(LinearSyncStatus::Pending);

    $last = (new DeliverLinearComment($delivery->id))->withFakeQueueInteractions();
    $last->job->attempts = 5;
    $last->handle(app(LinearIssueSync::class));

    $last->assertNotReleased();
    expect($delivery->fresh()->status)->toBe(LinearSyncStatus::Failed);
});

test('a job that exhausted its attempts fails the work it was carrying', function () {
    $link = LinearIssueLink::factory()->for($this->ticket, 'linkable')->for($this->owner, 'owner')->create();
    $delivery = LinearCommentDelivery::factory()->for(
        LinearIssueLink::factory()->for(Ticket::factory()->for($this->owner)->create(), 'linkable')->for($this->owner, 'owner')->synced()->create(),
        'issueLink',
    )->create();

    (new CreateLinearIssue($link->id))->failed(new RuntimeException('Queue gave up'));
    (new DeliverLinearComment($delivery->id))->failed(new LinearApiException('Linear said no.', LinearApiException::TRANSIENT));

    expect($link->fresh())->status->toBe(LinearSyncStatus::Failed)->last_error->toBe('Something went wrong while syncing with Linear. Try again shortly.')
        ->and($delivery->fresh())->status->toBe(LinearSyncStatus::Failed)->last_error->toBe('Linear said no.');
});

test('a job that failed after its work succeeded changes nothing', function () {
    $link = LinearIssueLink::factory()->for($this->ticket, 'linkable')->for($this->owner, 'owner')->synced()->create();
    $delivery = LinearCommentDelivery::factory()->for($link, 'issueLink')->create(['status' => LinearSyncStatus::Synced]);

    (new CreateLinearIssue($link->id))->failed(null);
    (new CreateLinearIssue(9999))->failed(null);
    (new DeliverLinearComment($delivery->id))->failed(null);
    (new DeliverLinearComment(9999))->failed(null);

    expect($link->fresh()->status)->toBe(LinearSyncStatus::Synced)
        ->and($delivery->fresh()->status)->toBe(LinearSyncStatus::Synced);
});

test('exceptions are classified for retrying', function () {
    Exceptions::fake();

    expect(CreateLinearIssue::isRetryable(new LinearApiException('x', LinearApiException::RATE_LIMITED)))->toBeTrue()
        ->and(CreateLinearIssue::isRetryable(new LinearApiException('x', LinearApiException::FORBIDDEN)))->toBeFalse()
        ->and(CreateLinearIssue::isRetryable(new RuntimeException('x')))->toBeTrue()
        ->and(CreateLinearIssue::isRetryable(null))->toBeTrue();

    Exceptions::assertReportedCount(1);
});
