<?php

declare(strict_types=1);

use Dniccum\Linear\Enums\LinearSyncStatus;
use Dniccum\Linear\Facades\Linear;
use Dniccum\Linear\Models\LinearCommentDelivery;
use Dniccum\Linear\Models\LinearConnection;
use Dniccum\Linear\Models\LinearIssueLink;
use Dniccum\Linear\Services\LinearIssueSync;
use Dniccum\Linear\Tests\Fixtures\Reply;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Workbench\App\Models\Ticket;
use Workbench\App\Models\User;

beforeEach(function () {
    $this->owner = User::factory()->create(['name' => 'Olive Owner']);
    $this->ticket = Ticket::factory()->for($this->owner)->create(['title' => 'Cannot upload']);
    LinearConnection::factory()->for($this->owner, 'owner')->create();
});

function reply(Ticket $ticket, string $body = 'We are looking into it.'): Reply
{
    return Reply::query()->create(['ticket_id' => $ticket->id, 'body' => $body]);
}

test('a comment on a linked model is posted once', function () {
    fakeLinearApi(['CreateComment' => linearCommentCreated()]);
    $link = LinearIssueLink::factory()->for($this->ticket, 'linkable')->for($this->owner, 'owner')->synced()->create();
    $reply = reply($this->ticket);

    $delivery = $this->ticket->commentOnLinear($reply->body, $reply);

    expect($delivery)->toBeInstanceOf(LinearCommentDelivery::class)
        ->and($delivery->fresh())
        ->status->toBe(LinearSyncStatus::Synced)
        ->delivered_at->not->toBeNull()
        ->source_type->toBe(Reply::class)
        ->source_id->toBe($reply->id)
        ->and($delivery->source?->is($reply))->toBeTrue()
        ->and(linearOperationsSent())->toBe(['CreateComment']);

    Http::assertSent(function (Request $request) use ($link, $delivery) {
        $input = $request['variables']['input'] ?? [];

        return ($input['id'] ?? null) === $delivery->linear_comment_id
            && $input['issueId'] === $link->linear_issue_id
            && $input['body'] === 'We are looking into it.';
    });

    // Queuing the same origin again must not create a second delivery.
    $this->ticket->commentOnLinear($reply->body, $reply);

    expect(LinearCommentDelivery::count())->toBe(1)
        ->and(linearOperationsSent())->toBe(['CreateComment']);
});

test('comments without an origin are each delivered', function () {
    fakeLinearApi(['CreateComment' => linearCommentCreated()]);
    LinearIssueLink::factory()->for($this->ticket, 'linkable')->for($this->owner, 'owner')->synced()->create();

    $first = Linear::comment($this->ticket, 'First');
    $second = Linear::comment($this->ticket, 'Second');

    expect($first->id)->not->toBe($second->id)
        ->and($first->source_type)->toBeNull()
        ->and($first->source)->toBeNull()
        ->and(linearOperationsSent())->toBe(['CreateComment', 'CreateComment']);
});

test('comments on models without an issue are not delivered', function () {
    fakeLinearApi();

    expect($this->ticket->commentOnLinear('Hello'))->toBeNull()
        ->and(Linear::comment($this->ticket, 'Hello'))->toBeNull()
        ->and(LinearCommentDelivery::count())->toBe(0);

    Http::assertNothingSent();
});

test('comments wait for a pending issue and are delivered once it is created', function () {
    fakeLinearApi([
        'CreateIssue' => linearIssueCreated(),
        'CreateComment' => linearCommentCreated(),
    ]);
    $link = LinearIssueLink::factory()->for($this->ticket, 'linkable')->for($this->owner, 'owner')->create();

    $this->ticket->commentOnLinear('Early comment');

    expect(LinearCommentDelivery::sole()->status)->toBe(LinearSyncStatus::Pending);
    Http::assertNothingSent();

    app(LinearIssueSync::class)->pushIssue($link);

    expect(LinearCommentDelivery::sole()->status)->toBe(LinearSyncStatus::Synced)
        ->and(linearOperationsSent())->toBe(['CreateIssue', 'CreateComment']);
});

test('a failed comment is retried without being posted twice', function () {
    fakeLinearApi([
        'FindComment' => fn (Request $request) => ['comment' => ['id' => $request['variables']['id'], 'url' => null]],
    ]);
    $link = LinearIssueLink::factory()->for($this->ticket, 'linkable')->for($this->owner, 'owner')->synced()->create();
    $delivery = LinearCommentDelivery::factory()->for($link, 'issueLink')->create([
        'status' => LinearSyncStatus::Failed,
        'attempts' => 1,
        'last_error' => 'Could not reach Linear.',
    ]);

    $this->ticket->retryLinear();

    expect($delivery->fresh())
        ->status->toBe(LinearSyncStatus::Synced)
        ->last_error->toBeNull()
        ->and(linearOperationsSent())->toBe(['FindComment']);
});

test('a comment created by an earlier attempt after a creation conflict is adopted', function () {
    fakeLinearApi([
        'CreateComment' => Http::response(['errors' => [['message' => 'Duplicate id']]], 400),
        'FindComment' => fn (Request $request) => ['comment' => ['id' => $request['variables']['id'], 'url' => null]],
    ]);
    LinearIssueLink::factory()->for($this->ticket, 'linkable')->for($this->owner, 'owner')->synced()->create();

    $delivery = $this->ticket->commentOnLinear('Hello');

    expect($delivery->fresh()->status)->toBe(LinearSyncStatus::Synced)
        ->and(linearOperationsSent())->toBe(['CreateComment', 'FindComment']);
});

test('a failing comment keeps its error', function () {
    fakeLinearApi(['CreateComment' => Http::response(['errors' => [[
        'message' => 'Forbidden',
        'extensions' => ['code' => 'FORBIDDEN', 'userPresentableMessage' => 'Missing comments:create.'],
    ]]], 400)]);
    LinearIssueLink::factory()->for($this->ticket, 'linkable')->for($this->owner, 'owner')->synced()->create();

    $this->ticket->commentOnLinear('Hello');

    expect(LinearCommentDelivery::sole())
        ->status->toBe(LinearSyncStatus::Failed)
        ->last_error->toContain('Missing comments:create.');
});

test('a comment awaiting its automatic retry is not offered or requeued for manual retry', function () {
    fakeLinearApi();
    $link = LinearIssueLink::factory()->for($this->ticket, 'linkable')->for($this->owner, 'owner')->synced()->create();
    $delivery = LinearCommentDelivery::factory()->for($link, 'issueLink')->create([
        'status' => LinearSyncStatus::Pending,
        'attempts' => 1,
        'last_error' => 'Linear is rate limiting requests.',
    ]);

    expect($link->canRetry())->toBeFalse();

    app(LinearIssueSync::class)->retry($link);

    expect($delivery->fresh()->status)->toBe(LinearSyncStatus::Pending)
        ->and($delivery->fresh()->attempts)->toBe(1);
    Http::assertNothingSent();
});

test('a link can be retried when it failed or when comments on it failed', function () {
    $failed = LinearIssueLink::factory()->for($this->ticket, 'linkable')->for($this->owner, 'owner')->failed()->create();
    $synced = LinearIssueLink::factory()->for(Ticket::factory()->for($this->owner)->create(), 'linkable')->for($this->owner, 'owner')->synced()->create();
    $pending = LinearIssueLink::factory()->for(Ticket::factory()->for($this->owner)->create(), 'linkable')->for($this->owner, 'owner')->create();

    expect($failed->canRetry())->toBeTrue()
        ->and($synced->canRetry())->toBeFalse()
        ->and($pending->canRetry())->toBeFalse();

    LinearCommentDelivery::factory()->for($synced, 'issueLink')->create(['status' => LinearSyncStatus::Failed]);

    expect($synced->canRetry())->toBeTrue();
});

test('retrying a failed issue also resends comments that failed with it', function () {
    fakeLinearApi([
        'FindIssue' => ['issue' => null],
        'CreateIssue' => linearIssueCreated(),
        'CreateComment' => linearCommentCreated(),
    ]);
    $link = LinearIssueLink::factory()->for($this->ticket, 'linkable')->for($this->owner, 'owner')->failed()->create();
    $delivery = LinearCommentDelivery::factory()->for($link, 'issueLink')->create([
        'status' => LinearSyncStatus::Failed,
        'last_error' => 'Linear was disconnected.',
    ]);

    app(LinearIssueSync::class)->retry($link);

    expect($link->fresh()->status)->toBe(LinearSyncStatus::Synced)
        ->and($delivery->fresh()->status)->toBe(LinearSyncStatus::Synced);
});

test('an already delivered comment is not sent again', function () {
    fakeLinearApi();
    $link = LinearIssueLink::factory()->for($this->ticket, 'linkable')->for($this->owner, 'owner')->synced()->create();
    $delivery = LinearCommentDelivery::factory()->for($link, 'issueLink')->create(['status' => LinearSyncStatus::Synced]);

    app(LinearIssueSync::class)->pushComment($delivery);

    Http::assertNothingSent();
});
