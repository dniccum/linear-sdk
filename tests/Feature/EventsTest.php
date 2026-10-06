<?php

declare(strict_types=1);

use Dniccum\Linear\Enums\LinearSyncStatus;
use Dniccum\Linear\Events\LinearCommentDelivered;
use Dniccum\Linear\Events\LinearIssueCreated;
use Dniccum\Linear\Events\LinearIssueFailed;
use Dniccum\Linear\Models\LinearConnection;
use Dniccum\Linear\Models\LinearDestination;
use Dniccum\Linear\Models\LinearIssueLink;
use Dniccum\Linear\Services\LinearIssueSync;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Workbench\App\Models\Ticket;
use Workbench\App\Models\User;

beforeEach(function () {
    $this->owner = User::factory()->create();
    LinearConnection::factory()->for($this->owner, 'owner')->create();
    LinearDestination::factory()->for($this->owner, 'owner')->create();
});

test('creating an issue dispatches LinearIssueCreated with the link', function () {
    fakeLinearApi(['CreateIssue' => linearIssueCreated('SUP-3')]);
    Event::fake([LinearIssueCreated::class, LinearIssueFailed::class, LinearCommentDelivered::class]);

    $ticket = Ticket::factory()->for($this->owner)->create();

    Event::assertDispatched(LinearIssueCreated::class, fn (LinearIssueCreated $event) => $event->link->linkable?->is($ticket) === true
        && $event->link->linear_issue_identifier === 'SUP-3'
        && $event->link->status === LinearSyncStatus::Synced);
    Event::assertNotDispatched(LinearIssueFailed::class);
});

test('adopting an issue an earlier attempt created also dispatches LinearIssueCreated', function () {
    fakeLinearApi(['FindIssue' => fn ($request) => ['issue' => ['id' => $request['variables']['id'], 'identifier' => 'SUP-9', 'url' => 'https://linear.app/acme/issue/SUP-9']]]);
    Event::fake([LinearIssueCreated::class]);

    $ticket = Ticket::factory()->create(['user_id' => null]);
    $link = LinearIssueLink::factory()->for($ticket, 'linkable')->for($this->owner, 'owner')->failed()->create();

    app(LinearIssueSync::class)->retry($link);

    Event::assertDispatched(LinearIssueCreated::class);
});

test('a permanent failure dispatches LinearIssueFailed with the message', function () {
    fakeLinearApi(['CreateIssue' => Http::response(['errors' => [['message' => 'Bad', 'extensions' => ['code' => 'FORBIDDEN', 'userPresentableMessage' => 'No access.']]]], 400)]);
    Event::fake([LinearIssueCreated::class, LinearIssueFailed::class]);

    Ticket::factory()->for($this->owner)->create();

    Event::assertDispatched(LinearIssueFailed::class, fn (LinearIssueFailed $event) => $event->message === 'Linear denied access: No access.'
        && $event->link->status === LinearSyncStatus::Failed);
    Event::assertNotDispatched(LinearIssueCreated::class);
});

test('a retryable failure does not dispatch LinearIssueFailed yet', function () {
    fakeLinearApi(['CreateIssue' => Http::response(['errors' => [['message' => 'Down']]], 503)]);
    Event::fake([LinearIssueFailed::class]);

    Ticket::factory()->for($this->owner)->create();

    Event::assertNotDispatched(LinearIssueFailed::class);
});

test('posting a comment dispatches LinearCommentDelivered', function () {
    fakeLinearApi(['CreateComment' => linearCommentCreated()]);
    Event::fake([LinearCommentDelivered::class]);

    $ticket = Ticket::factory()->create(['user_id' => null]);
    LinearIssueLink::factory()->for($ticket, 'linkable')->for($this->owner, 'owner')->synced()->create();

    $delivery = $ticket->commentOnLinear('Hello');

    Event::assertDispatched(LinearCommentDelivered::class, fn (LinearCommentDelivered $event) => $event->delivery->is($delivery)
        && $event->delivery->status === LinearSyncStatus::Synced);
});
