<?php

declare(strict_types=1);

use Dniccum\Linear\Models\LinearCommentDelivery;
use Dniccum\Linear\Models\LinearConnection;
use Dniccum\Linear\Models\LinearDestination;
use Dniccum\Linear\Models\LinearIssueLink;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;
use Workbench\App\Models\Ticket;
use Workbench\App\Models\User;

/**
 * @return list<Migration>
 */
function packageMigrations(): array
{
    return array_map(
        fn (string $name): Migration => require dirname(__DIR__, 2)."/database/migrations/create_linear_{$name}_table.php.stub",
        ['connections', 'destinations', 'issue_links', 'comment_deliveries'],
    );
}

test('the package creates its four tables', function () {
    expect(Schema::hasTable('linear_connections'))->toBeTrue()
        ->and(Schema::hasTable('linear_destinations'))->toBeTrue()
        ->and(Schema::hasTable('linear_issue_links'))->toBeTrue()
        ->and(Schema::hasTable('linear_comment_deliveries'))->toBeTrue();
});

test('the tables have the documented columns', function () {
    expect(Schema::getColumnListing('linear_connections'))->toEqualCanonicalizing([
        'id', 'owner_type', 'owner_id', 'auth_type', 'linear_organization_id', 'organization_name', 'organization_url_key',
        'linear_user_id', 'linear_user_name', 'linear_user_email', 'access_token', 'refresh_token', 'token_expires_at',
        'scopes', 'status', 'last_error', 'last_error_at', 'last_synced_at', 'created_at', 'updated_at',
    ])
        ->and(Schema::getColumnListing('linear_destinations'))->toEqualCanonicalizing([
            'id', 'owner_type', 'owner_id', 'linear_organization_id', 'send_mode', 'team_id', 'team_name', 'project_id',
            'state_id', 'label_ids', 'priority', 'assignee_id', 'created_at', 'updated_at',
        ])
        ->and(Schema::getColumnListing('linear_issue_links'))->toEqualCanonicalizing([
            'id', 'linkable_type', 'linkable_id', 'owner_type', 'owner_id', 'connection_id', 'linear_organization_id', 'source',
            'status', 'linear_issue_id', 'linear_issue_identifier', 'linear_issue_url', 'payload', 'attempts', 'last_error',
            'synced_at', 'created_at', 'updated_at',
        ])
        ->and(Schema::getColumnListing('linear_comment_deliveries'))->toEqualCanonicalizing([
            'id', 'linear_issue_link_id', 'source_type', 'source_id', 'status', 'linear_comment_id', 'body', 'attempts',
            'last_error', 'delivered_at', 'created_at', 'updated_at',
        ]);
});

test('the models follow the table prefix', function () {
    config(['linear.table_prefix' => 'acme_']);

    expect((new LinearConnection)->getTable())->toBe('acme_connections')
        ->and((new LinearDestination)->getTable())->toBe('acme_destinations')
        ->and((new LinearIssueLink)->getTable())->toBe('acme_issue_links')
        ->and((new LinearCommentDelivery)->getTable())->toBe('acme_comment_deliveries');
});

test('the migrations honour the table prefix and can be rolled back', function () {
    config(['linear.table_prefix' => 'acme_']);

    $migrations = packageMigrations();

    foreach ($migrations as $migration) {
        $migration->up();
    }

    expect(Schema::hasTable('acme_connections'))->toBeTrue()
        ->and(Schema::hasTable('acme_destinations'))->toBeTrue()
        ->and(Schema::hasTable('acme_issue_links'))->toBeTrue()
        ->and(Schema::hasTable('acme_comment_deliveries'))->toBeTrue();

    $user = User::factory()->create();
    LinearConnection::factory()->for($user, 'owner')->create();

    expect(LinearConnection::query()->count())->toBe(1);

    foreach (array_reverse($migrations) as $migration) {
        $migration->down();
    }

    expect(Schema::hasTable('acme_connections'))->toBeFalse()
        ->and(Schema::hasTable('acme_comment_deliveries'))->toBeFalse()
        ->and(Schema::hasTable('linear_connections'))->toBeTrue();
});

test('the morph columns follow the configured key type', function (string $type, string $expected) {
    config(['linear.table_prefix' => "{$type}_", 'linear.key_type' => $type]);

    foreach (packageMigrations() as $migration) {
        $migration->up();
    }

    $columnType = fn (string $table, string $column): string => collect(Schema::getColumns($table))->firstWhere('name', $column)['type_name'];

    expect($columnType("{$type}_connections", 'owner_id'))->toBe($expected)
        ->and($columnType("{$type}_destinations", 'owner_id'))->toBe($expected)
        ->and($columnType("{$type}_issue_links", 'linkable_id'))->toBe($expected)
        ->and($columnType("{$type}_issue_links", 'owner_id'))->toBe($expected)
        ->and($columnType("{$type}_comment_deliveries", 'source_id'))->toBe($expected);
})->with([
    'integer keys' => ['int', 'integer'],
    'uuid keys' => ['uuid', 'varchar'],
    'ulid keys' => ['ulid', 'varchar'],
]);

test('a model can be linked to one issue only', function () {
    $user = User::factory()->create();
    $ticket = Ticket::factory()->for($user)->create();

    LinearIssueLink::factory()->for($ticket, 'linkable')->for($user, 'owner')->create();

    expect(fn () => LinearIssueLink::factory()->for($ticket, 'linkable')->for($user, 'owner')->create())->toThrow(UniqueConstraintViolationException::class);
});

test('an issue UUID is unique', function () {
    $user = User::factory()->create();

    LinearIssueLink::factory()->for(Ticket::factory()->create(), 'linkable')->for($user, 'owner')->create(['linear_issue_id' => 'dup']);

    expect(fn () => LinearIssueLink::factory()->for(Ticket::factory()->create(), 'linkable')->for($user, 'owner')->create(['linear_issue_id' => 'dup']))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('an owner has one connection and one destination', function () {
    $user = User::factory()->create();

    LinearConnection::factory()->for($user, 'owner')->create();
    LinearDestination::factory()->for($user, 'owner')->create();

    expect(fn () => LinearConnection::factory()->for($user, 'owner')->create())->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => LinearDestination::factory()->for($user, 'owner')->create())->toThrow(UniqueConstraintViolationException::class);
});

test('each comment origin is delivered once but ad hoc comments are unlimited', function () {
    $user = User::factory()->create();
    $link = LinearIssueLink::factory()->for(Ticket::factory()->create(), 'linkable')->for($user, 'owner')->create();
    $origin = Ticket::factory()->create();

    LinearCommentDelivery::factory()->for($link, 'issueLink')->create(['source_type' => $origin->getMorphClass(), 'source_id' => $origin->id]);
    LinearCommentDelivery::factory()->for($link, 'issueLink')->count(3)->create();

    expect(LinearCommentDelivery::count())->toBe(4)
        ->and(fn () => LinearCommentDelivery::factory()->for($link, 'issueLink')->create(['source_type' => $origin->getMorphClass(), 'source_id' => $origin->id]))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('deleting a connection keeps the link and clears its connection', function () {
    $user = User::factory()->create();
    $connection = LinearConnection::factory()->for($user, 'owner')->create();
    $link = LinearIssueLink::factory()->for(Ticket::factory()->create(), 'linkable')->for($user, 'owner')->create(['connection_id' => $connection->id]);

    expect($link->connection->is($connection))->toBeTrue();

    $connection->delete();

    expect($link->fresh()->connection_id)->toBeNull()->and($link->fresh()->connection)->toBeNull();
});

test('deleting a link deletes its comment deliveries', function () {
    $user = User::factory()->create();
    $link = LinearIssueLink::factory()->for(Ticket::factory()->create(), 'linkable')->for($user, 'owner')->create();
    LinearCommentDelivery::factory()->for($link, 'issueLink')->count(2)->create();

    expect($link->commentDeliveries)->toHaveCount(2);

    $link->delete();

    expect(LinearCommentDelivery::count())->toBe(0);
});
