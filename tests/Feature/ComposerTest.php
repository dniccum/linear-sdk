<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Dniccum\Linear\Enums\LinearSendMode;
use Dniccum\Linear\Services\IssueComposer;
use Dniccum\Linear\Tests\Fixtures\CustomTicket;
use Dniccum\Linear\Tests\Fixtures\PlainModel;
use Illuminate\Database\Eloquent\Model;
use Workbench\App\Models\Ticket;
use Workbench\App\Models\User;

/**
 * A model with the given attributes, named `Support Request` by its class.
 *
 * @param  array<string, mixed>  $attributes
 */
function composedModel(array $attributes, ?int $key = 42): Model
{
    $model = new PlainModel;
    $model->forceFill($attributes);

    if ($key !== null) {
        $model->setAttribute('id', $key);
    }

    return $model;
}

beforeEach(function () {
    $this->composer = app(IssueComposer::class);
});

test('the title is the first filled title, subject or name', function () {
    expect($this->composer->defaultTitle(composedModel(['title' => '  Cannot upload ', 'subject' => 'Other', 'name' => 'Name'])))->toBe('Cannot upload')
        ->and($this->composer->defaultTitle(composedModel(['title' => '', 'subject' => 'Billing question'])))->toBe('Billing question')
        ->and($this->composer->defaultTitle(composedModel(['title' => null, 'subject' => '   ', 'name' => 'Ada'])))->toBe('Ada')
        ->and($this->composer->defaultTitle(composedModel(['title' => 12])))->toBe('Plain Model #42')
        ->and($this->composer->defaultTitle(composedModel([], key: null)))->toBe('Plain Model #');
});

test('titles are capped to what Linear accepts', function () {
    $title = $this->composer->title(composedModel(['title' => str_repeat('a', 400)]));

    expect(strlen($title))->toBeLessThanOrEqual(255)->and($title)->toEndWith('...');
});

test('the description names the record and lists its attributes', function () {
    $model = composedModel(['title' => 'Cannot upload', 'status' => 'open', 'email' => 'jane@example.com']);

    expect($this->composer->defaultDescription($model))->toBe(
        "**Plain Model #42**\n\n**Details**\n- Title: Cannot upload\n- Status: open\n- Email: jane@example.com"
    );
});

test('a record without printable attributes has a header only', function () {
    expect($this->composer->defaultDescription(composedModel([])))->toBe('**Plain Model #42**');
});

test('values are printed the way a person would read them', function () {
    $model = composedModel([
        'flag' => true,
        'off' => false,
        'count' => 3,
        'price' => 9.5,
        'when' => CarbonImmutable::parse('2026-03-04T05:06:07Z'),
        'mode' => LinearSendMode::Manual,
        'tags' => ['a', 'b'],
        'nothing' => null,
        'blank' => '   ',
        'object' => new stdClass, // not printable: skipped
        'stringable' => new class implements Stringable
        {
            public function __toString(): string
            {
                return 'stringified';
            }
        },
        'arrayable' => collect(['x' => 1]),
        'json' => new class implements JsonSerializable
        {
            public function jsonSerialize(): array
            {
                return ['y' => 2];
            }
        },
    ]);

    expect($this->composer->defaultDescription($model))->toBe(implode("\n", [
        '**Plain Model #42**',
        '',
        '**Details**',
        '- Flag: Yes',
        '- Off: No',
        '- Count: 3',
        '- Price: 9.5',
        '- When: 2026-03-04T05:06:07+00:00',
        '- Mode: manual',
        '- Tags: ["a","b"]',
        '- Stringable: stringified',
        '- Arrayable: {"x":1}',
        '- Json: {"y":2}',
    ]));
});

test('multi-line values are quoted', function () {
    $model = composedModel(['message' => "Uploads fail.\r\nEvery time.\n\nHelp!"]);

    expect($this->composer->defaultDescription($model))->toContain("- Message:\n  > Uploads fail.\n  > Every time.\n  >\n  > Help!");
});

test('the visible attributes win over the fillable ones, which win over everything', function () {
    $visible = composedModel(['a' => 'A', 'b' => 'B', 'c' => 'C'])->setVisible(['a', 'b']);
    $fillable = composedModel(['a' => 'A', 'b' => 'B', 'c' => 'C'])->fillable(['b', 'c']);
    $all = composedModel(['a' => 'A', 'b' => 'B']);

    expect($this->composer->defaultDescription($visible))->toContain('- A: A')->toContain('- B: B')->not->toContain('- C:')
        ->and($this->composer->defaultDescription($fillable))->not->toContain('- A:')->toContain('- B: B')->toContain('- C: C')
        ->and($this->composer->defaultDescription($all))->toContain('- A: A')->toContain('- B: B');
});

test('hidden attributes and credentials are never listed', function () {
    $model = composedModel(['name' => 'Ada', 'password' => 'secret', 'remember_token' => 'tok', 'api_token' => 'api', 'access_token' => 'acc', 'refresh_token' => 'ref', 'private' => 'hidden'])
        ->makeHidden(['private']);

    $description = $this->composer->defaultDescription($model);

    expect($description)->toContain('- Name: Ada')
        ->not->toContain('secret')->not->toContain('tok')->not->toContain('api')->not->toContain('acc')->not->toContain('ref')->not->toContain('hidden')
        ->and($this->composer->defaultDescription(composedModel(['password' => 'x'])->setVisible(['password'])->makeVisible(['password'])))->toBe('**Plain Model #42**');
});

test('a comment names the event and lists the changes', function () {
    $model = composedModel(['title' => 'Old']);

    expect($this->composer->defaultComment($model, 'deleted'))->toBe('**Plain Model #42** was deleted.')
        ->and($this->composer->defaultComment($model, 'updated', ['changes' => ['title' => 'New', 'status' => 'closed', 'password' => 'secret', 'empty' => null]]))
        ->toBe("**Plain Model #42** was updated.\n\n- Title: New\n- Status: closed")
        ->and($this->composer->defaultComment($model, 'updated', ['changes' => 'nonsense']))->toBe('**Plain Model #42** was updated.');
});

test('a model\'s own hooks win over the defaults', function () {
    $custom = CustomTicket::query()->create(['user_id' => User::factory()->create()->id, 'title' => 'Draft']);
    $plain = Ticket::factory()->create(['title' => 'Plain']);

    expect($this->composer->title($custom))->toBe('Custom: Draft')
        ->and($this->composer->description($custom))->toBe('Custom description')
        ->and($this->composer->comment($custom, 'updated'))->toBe('Custom updated comment')
        ->and($this->composer->title($plain))->toBe('Plain')
        ->and($this->composer->description($plain))->toContain("**Ticket #{$plain->id}**")
        ->and($this->composer->comment($plain, 'deleted'))->toBe("**Ticket #{$plain->id}** was deleted.")
        ->and($plain->linearTitle())->toBe('Plain')
        ->and($plain->linearDescription())->toBe($this->composer->description($plain))
        ->and($plain->linearComment('deleted'))->toBe($this->composer->comment($plain, 'deleted'));
});

test('a model without hooks is composed with the defaults', function () {
    $model = composedModel(['name' => 'Ada']);

    expect($this->composer->title($model))->toBe('Ada')
        ->and($this->composer->description($model))->toContain('- Name: Ada')
        ->and($this->composer->comment($model, 'deleted'))->toBe('**Plain Model #42** was deleted.')
        ->and($this->composer->label($model))->toBe('Plain Model #42');
});
