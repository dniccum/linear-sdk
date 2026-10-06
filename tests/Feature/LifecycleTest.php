<?php

declare(strict_types=1);

use Dniccum\Linear\Enums\LinearSyncStatus;
use Dniccum\Linear\Exceptions\LinearApiException;
use Dniccum\Linear\Facades\Linear;
use Dniccum\Linear\Models\LinearConnection;
use Dniccum\Linear\Models\LinearDestination;
use Dniccum\Linear\Models\LinearIssueLink;
use Dniccum\Linear\Services\LinearIssueSync;
use Dniccum\Linear\Tests\Fixtures\CustomTicket;
use Dniccum\Linear\Tests\Fixtures\EventedTicket;
use Dniccum\Linear\Tests\Fixtures\Note;
use Dniccum\Linear\Tests\Fixtures\OrphanTicket;
use Dniccum\Linear\Tests\Fixtures\PlainModel;
use Dniccum\Linear\Tests\Fixtures\SilentTicket;
use Dniccum\Linear\Tests\Fixtures\UpdatedOnlyTicket;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Exceptions;
use Workbench\App\Models\Ticket;
use Workbench\App\Models\User;

beforeEach(function () {
    $this->fake = Linear::fake();
    $this->owner = User::factory()->create();
    LinearConnection::factory()->for($this->owner, 'owner')->create();
    LinearDestination::factory()->for($this->owner, 'owner')->create(['team_id' => 'team-1']);
});

test('by default only the created event files a model', function () {
    $ticket = Ticket::factory()->for($this->owner)->create();

    $ticket->update(['title' => 'Changed']);
    $ticket->delete();

    $this->fake->assertIssueCreatedCount(1)->assertNoCommentPosted();
    expect(LinearIssueLink::count())->toBe(1);
});

test('a model that reacts to no events is never filed', function () {
    SilentTicket::query()->create(['user_id' => $this->owner->id, 'title' => 'Quiet']);

    $this->fake->assertNoIssueCreated();
    expect(LinearIssueLink::count())->toBe(0);
});

test('a model that reacts to updates is filed on its first update, once', function () {
    $ticket = UpdatedOnlyTicket::query()->create(['user_id' => $this->owner->id, 'title' => 'Draft']);

    $this->fake->assertNoIssueCreated();

    $ticket->update(['title' => 'Final']);
    $ticket->update(['title' => 'Final, really']);

    $this->fake->assertIssueCreatedCount(1);
    expect($ticket->fresh()->linear_sync_status)->toBe(LinearSyncStatus::Synced);
});

test('updating a linked model does nothing unless comments are enabled', function () {
    $ticket = EventedTicket::query()->create(['user_id' => $this->owner->id, 'title' => 'Draft']);

    $ticket->update(['title' => 'Final']);

    $this->fake->assertIssueCreatedCount(1)->assertNoCommentPosted();
});

test('updating a linked model can post a comment listing what changed', function () {
    config(['linear.on_update' => 'comment']);

    $ticket = EventedTicket::query()->create(['user_id' => $this->owner->id, 'title' => 'Draft', 'body' => 'First']);

    $ticket->update(['title' => 'Final', 'status' => 'closed']);

    $this->fake->assertCommentPosted(fn (string $body) => str_contains($body, '**Evented Ticket #'.$ticket->id.'** was updated.')
        && str_contains($body, '- Title: Final')
        && str_contains($body, '- Status: closed')
        && ! str_contains($body, 'Body')
        && ! str_contains($body, 'Updated At'));
});

test('an update that changes nothing but the timestamp posts no comment', function () {
    config(['linear.on_update' => 'comment']);

    $ticket = EventedTicket::query()->create(['user_id' => $this->owner->id, 'title' => 'Draft']);

    $this->travel(5)->minutes();
    $ticket->touch();

    $this->fake->assertNoCommentPosted();
});

test('deleting a linked model can post a comment', function () {
    config(['linear.on_delete' => 'comment']);

    $ticket = EventedTicket::query()->create(['user_id' => $this->owner->id, 'title' => 'Draft']);

    $ticket->delete();

    $this->fake->assertCommentPosted(fn (string $body) => $body === '**Evented Ticket #'.$ticket->id.'** was deleted.');
});

test('deleting a linked model is ignored by default, and so is deleting an unlinked one', function () {
    $linked = EventedTicket::query()->create(['user_id' => $this->owner->id, 'title' => 'Draft']);
    $linked->delete();

    config(['linear.on_delete' => 'comment']);
    LinearIssueLink::query()->delete();

    $unlinked = EventedTicket::query()->create(['user_id' => $this->owner->id, 'title' => 'Another']);
    LinearIssueLink::query()->delete();
    $unlinked->delete();

    $this->fake->assertNoCommentPosted();
});

test('a model can compose its own issue and comments', function () {
    config(['linear.on_update' => 'comment', 'linear.on_delete' => 'comment']);

    $ticket = CustomTicket::query()->create(['user_id' => $this->owner->id, 'title' => 'Draft']);

    $ticket->update(['title' => 'Final']);
    $ticket->delete();

    $this->fake
        ->assertIssueCreated(fn ($payload) => $payload->title === 'Custom: Draft'
            && $payload->description === 'Custom description'
            && $payload->destination->teamId === 'team-override'
            && $payload->destination->priority === 1)
        ->assertCommentPosted(fn (string $body) => $body === 'Custom updated comment')
        ->assertCommentPosted(fn (string $body) => $body === 'Custom deleted comment');
});

test('a model without an owner relation files nothing', function () {
    Note::query()->create(['ticket_id' => Ticket::factory()->create()->id, 'body' => 'Orphan']);

    $this->fake->assertNoIssueCreated();
});

test('a model can name its owner explicitly', function () {
    OrphanTicket::$owner = $this->owner;

    try {
        OrphanTicket::query()->create(['title' => 'Owned elsewhere']);
    } finally {
        OrphanTicket::$owner = null;
    }

    $this->fake->assertIssueCreatedCount(1);

    OrphanTicket::query()->create(['title' => 'Nobody']);

    $this->fake->assertIssueCreatedCount(1);
});

test('a Linear problem never breaks the host save', function () {
    Exceptions::fake();
    app()->bind(LinearIssueSync::class, fn () => throw new RuntimeException('Linear exploded'));

    $ticket = EventedTicket::query()->create(['user_id' => $this->owner->id, 'title' => 'Draft']);
    $ticket->update(['title' => 'Final']);
    $ticket->delete();

    expect(EventedTicket::query()->count())->toBe(0);

    Exceptions::assertReportedCount(3);
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'Linear exploded');
});

test('an owner that cannot hold a connection is reported, not thrown', function () {
    Exceptions::fake();

    $ticket = new class extends OrphanTicket
    {
        public function linearOwner(): ?Model
        {
            return new PlainModel;
        }
    };
    $ticket->forceFill(['title' => 'Odd'])->save();

    expect($ticket->exists)->toBeTrue();

    Exceptions::assertReported(fn (LogicException $e) => str_contains($e->getMessage(), 'HasLinearConnection'));
});

test('the failure of an automatic issue is visible on the model', function () {
    $this->fake->failWith('createIssue', new LinearApiException('Team was deleted.', LinearApiException::INVALID_REQUEST));

    $ticket = Ticket::factory()->for($this->owner)->create();

    expect($ticket->fresh())
        ->linear_sync_status->toBe(LinearSyncStatus::Failed)
        ->linear_issue_url->toBeNull()
        ->linear_issue_identifier->toBeNull();
});

test('events other than created, updated and deleted are ignored', function () {
    $ticket = Ticket::factory()->for($this->owner)->create();

    app(LinearIssueSync::class)->handleEvent($ticket, 'restored');

    $this->fake->assertIssueCreatedCount(1)->assertNoCommentPosted();
});
