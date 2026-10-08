<?php

declare(strict_types=1);

use Dniccum\Linear\Actions\ListTeamOptions;
use Dniccum\Linear\Actions\ListTeams;
use Dniccum\Linear\Data\Destination;
use Dniccum\Linear\Data\Member;
use Dniccum\Linear\Data\Project;
use Dniccum\Linear\Data\Team;
use Dniccum\Linear\Data\TeamOptions;
use Dniccum\Linear\Data\WorkflowState;
use Dniccum\Linear\Enums\LinearPriority;
use Dniccum\Linear\Enums\LinearStateType;
use Dniccum\Linear\Facades\Linear;
use Dniccum\Linear\Models\LinearConnection;
use Dniccum\Linear\Support\Emoji;
use Workbench\App\Models\User;

beforeEach(function () {
    $this->owner = User::factory()->create();
    LinearConnection::factory()->for($this->owner, 'owner')->create();
});

test('the headless actions return DTOs carrying the visual fields', function () {
    fakeLinearApi([
        ...linearTeamOptionsOperations(),
        'Teams' => ['teams' => ['nodes' => [['id' => 'team-1', 'name' => 'Support', 'key' => 'SUP', 'color' => '#5e6ad2', 'icon' => '🛟']]]],
    ]);

    $teams = app(ListTeams::class)->execute($this->owner);
    $options = app(ListTeamOptions::class)->execute($this->owner, 'team-1');

    expect($teams)->toEqual([new Team('team-1', 'Support', 'SUP', '#5e6ad2', '🛟')])
        ->and($teams[0]->iconIsEmoji())->toBeTrue()
        ->and($options->team->color)->toBe('#5e6ad2')
        ->and($options->projects[0])->toEqual(new Project('project-1', 'Inbox', '#4cb782', '📥'))
        ->and($options->states[0])->toEqual(new WorkflowState('state-1', 'Triage', 'triage', '#bec2c8'))
        ->and($options->states[0]->kind())->toBe(LinearStateType::Triage)
        ->and($options->members[0])->toEqual(new Member('user-1', 'ada', 'https://public.linear.app/ada.png', 'AL', '#5e6ad2'));
});

test('the headless actions return null visuals when Linear omits them', function () {
    fakeLinearApi([
        'Teams' => ['teams' => ['nodes' => [['id' => 'team-1', 'name' => 'Support', 'key' => 'SUP']]]],
        ...linearTeamOptionsOperations(),
        'TeamOptions' => ['team' => ['id' => 'team-1', 'name' => 'Support', 'key' => 'SUP', 'states' => ['nodes' => [['id' => 's', 'name' => 'Open', 'type' => 'started']]]]],
        'TeamProjects' => ['team' => ['projects' => ['nodes' => [['id' => 'p', 'name' => 'Bare']], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]],
        'TeamMembers' => ['team' => ['members' => ['nodes' => [['id' => 'u', 'name' => 'Bare Member']], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]],
    ]);

    $team = app(ListTeams::class)->execute($this->owner)[0];
    $options = app(ListTeamOptions::class)->execute($this->owner, 'team-1');

    expect($team->toArray())->toBe(['id' => 'team-1', 'name' => 'Support', 'key' => 'SUP', 'color' => null, 'icon' => null])
        ->and($team->iconIsEmoji())->toBeFalse()
        ->and($options->states[0]->toArray())->toBe(['id' => 's', 'name' => 'Open', 'type' => 'started', 'color' => null])
        ->and($options->projects[0]->toArray())->toBe(['id' => 'p', 'name' => 'Bare', 'color' => null, 'icon' => null])
        ->and($options->members[0]->toArray())->toBe(['id' => 'u', 'name' => 'Bare Member', 'avatarUrl' => null, 'initials' => null, 'avatarBackgroundColor' => null])
        ->and($options->members[0]->displayInitials())->toBe('BM');
});

test('the client returned by Linear::client() exposes the same DTOs', function () {
    fakeLinearApi(linearTeamOptionsOperations());

    $client = Linear::client($this->owner);

    expect($client->teamOptions('team-1')->projects[0]->icon)->toBe('📥');
});

test('Linear::fake() accepts DTOs carrying the visual fields', function () {
    $fake = Linear::fake();
    $options = new TeamOptions(
        team: new Team('team-9', 'Bugs', 'BUG', '#eb5757', 'Bug'),
        projects: [new Project('p-1', 'Triage', '#26b5ce', '🚦')],
        states: [new WorkflowState('s-1', 'Open', 'unstarted', '#e2e2e2')],
        labels: [],
        members: [new Member('m-1', 'Grace Hopper', null, 'GH', '#f2994a')],
    );
    $fake->withTeams([$options->team])->withTeamOptions($options);

    $this->actingAs($this->owner)->getJson(route('linear.api.teams'))
        ->assertExactJson(['teams' => [['id' => 'team-9', 'name' => 'Bugs', 'key' => 'BUG', 'color' => '#eb5757', 'icon' => 'Bug']]]);
    $this->actingAs($this->owner)->getJson(route('linear.api.team-options', 'team-9'))
        ->assertJsonPath('projects.0.icon', '🚦')
        ->assertJsonPath('states.0.color', '#e2e2e2')
        ->assertJsonPath('members.0.initials', 'GH')
        ->assertJsonPath('members.0.avatarUrl', null);
});

test('the default fake data carries visuals', function () {
    Linear::fake();

    $this->actingAs($this->owner)->getJson(route('linear.api.team-options', 'team-1'))
        ->assertJsonPath('projects.0.color', '#4cb782')
        ->assertJsonPath('states.0.color', '#bec2c8')
        ->assertJsonPath('members.0.initials', 'AL');
});

test('icons are recognised as emoji or names, never as URLs', function (?string $icon, bool $emoji) {
    expect(Emoji::is($icon))->toBe($emoji)
        ->and((new Team('t', 'T', 'T', null, $icon))->iconIsEmoji())->toBe($emoji)
        ->and((new Project('p', 'P', null, $icon))->iconIsEmoji())->toBe($emoji);
})->with([
    'rocket' => ['🚀', true],
    'flag' => ['🇳🇱', true],
    'emoji then text' => ['🛟 support', true],
    'dingbat' => ['✅', true],
    'icon name' => ['Bug', false],
    'shortcode' => [':rocket:', false],
    'digit' => ['1', false],
    'empty' => ['', false],
    'null' => [null, false],
]);

test('members without initials fall back to their name', function (string $name, ?string $initials, string $expected) {
    expect((new Member('m', $name, null, $initials))->displayInitials())->toBe($expected);
})->with([
    'linear initials win' => ['Ada Lovelace', 'xy', 'xy'],
    'two words' => ['ada lovelace', null, 'AL'],
    'three words' => ['Ada Byron Lovelace', null, 'AB'],
    'one word' => ['grace', null, 'G'],
    'unicode' => ['édith piaf', null, 'ÉP'],
    'blank' => ['  ', null, '?'],
]);

test('workflow state types map to an enum when known', function () {
    expect((new WorkflowState('s', 'x', 'completed'))->kind())->toBe(LinearStateType::Completed)
        ->and((new WorkflowState('s', 'x', 'mystery'))->kind())->toBeNull()
        ->and(array_map(fn (LinearStateType $type): string => $type->label(), LinearStateType::cases()))
        ->toBe(['Triage', 'Backlog', 'Unstarted', 'Started', 'Completed', 'Canceled', 'Duplicate']);
});

test('the priority enum lists Linear\'s scale', function () {
    expect(LinearPriority::options())->toBe([
        ['value' => 0, 'label' => 'No priority'],
        ['value' => 1, 'label' => 'Urgent'],
        ['value' => 2, 'label' => 'High'],
        ['value' => 3, 'label' => 'Medium'],
        ['value' => 4, 'label' => 'Low'],
    ])
        ->and(LinearPriority::fromNumber(null))->toBe(LinearPriority::NoPriority)
        ->and(LinearPriority::fromNumber(3))->toBe(LinearPriority::Medium)
        ->and(LinearPriority::fromNumber(9))->toBe(LinearPriority::NoPriority)
        ->and((new Destination('t', priority: 1))->priorityLevel())->toBe(LinearPriority::Urgent)
        ->and((new Destination('t'))->priorityLevel())->toBe(LinearPriority::NoPriority);
});
