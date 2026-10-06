<?php

declare(strict_types=1);

use Dniccum\Linear\Data\Comment;
use Dniccum\Linear\Data\Destination;
use Dniccum\Linear\Data\Issue;
use Dniccum\Linear\Data\IssuePayload;
use Dniccum\Linear\Data\Label;
use Dniccum\Linear\Data\Member;
use Dniccum\Linear\Data\Organization;
use Dniccum\Linear\Data\Project;
use Dniccum\Linear\Data\ResolvedDestination;
use Dniccum\Linear\Data\Team;
use Dniccum\Linear\Data\TeamOptions;
use Dniccum\Linear\Data\Tokens;
use Dniccum\Linear\Data\Viewer;
use Dniccum\Linear\Data\WorkflowState;
use Dniccum\Linear\Exceptions\LinearApiException;
use Dniccum\Linear\Support\Json;
use Illuminate\Contracts\Support\Arrayable;

test('tokens parse comma or space separated and array scopes', function (mixed $scope) {
    $tokens = Tokens::fromResponse([
        'access_token' => 'access',
        'refresh_token' => 'refresh',
        'expires_in' => '3600',
        'scope' => $scope,
    ]);

    expect($tokens->scopes)->toBe(['read', 'issues:create'])
        ->and($tokens->expiresIn)->toBe(3600)
        ->and($tokens->expiresAt()?->isFuture())->toBeTrue()
        ->and($tokens->missingScopes(['read', 'comments:create']))->toBe(['comments:create']);
})->with([
    'comma separated' => ['read,issues:create'],
    'space separated' => ['read issues:create'],
    'array' => [['read', 'issues:create']],
]);

test('tokens without scopes or expiry report every scope missing', function () {
    $tokens = Tokens::fromResponse(['access_token' => 'access']);

    expect($tokens->scopes)->toBe([])
        ->and($tokens->refreshToken)->toBeNull()
        ->and($tokens->expiresAt())->toBeNull()
        ->and($tokens->missingScopes(['read']))->toBe(['read']);
});

test('tokens are never serializable', function () {
    expect(Tokens::fromResponse(['access_token' => 'secret']))
        ->not->toBeInstanceOf(JsonSerializable::class)
        ->not->toBeInstanceOf(Arrayable::class);
});

test('a destination normalizes blank and duplicate input', function () {
    $destination = Destination::fromArray([
        'team_id' => 'team-1',
        'project_id' => '',
        'state_id' => null,
        'label_ids' => ['label-1', 'label-1', 'label-2'],
        'priority' => '2',
    ]);

    expect($destination)->toEqual(new Destination(
        teamId: 'team-1',
        labelIds: ['label-1', 'label-2'],
        priority: 2,
    ))->and($destination->toArray())->toBe([
        'team_id' => 'team-1',
        'project_id' => null,
        'state_id' => null,
        'label_ids' => ['label-1', 'label-2'],
        'priority' => 2,
        'assignee_id' => null,
    ]);
});

test('a destination tolerates missing or malformed input', function () {
    expect(Destination::fromArray([])->teamId)->toBe('')
        ->and(Destination::fromArray(['team_id' => 't', 'label_ids' => 'nope', 'priority' => 'high'])->labelIds)->toBe([])
        ->and(Destination::fromArray(['team_id' => 't', 'priority' => 'high'])->priority)->toBeNull()
        ->and(Destination::fromArray(['team_id' => 't', 'priority' => 0])->priority)->toBe(0);
});

test('a destination can be copied with changes', function () {
    $destination = new Destination('team-1', projectId: 'project-1', priority: 2);

    expect($destination->with(['team_id' => 'team-2', 'priority' => null, 'label_ids' => ['l']]))
        ->toEqual(new Destination('team-2', projectId: 'project-1', labelIds: ['l']))
        ->and($destination->teamId)->toBe('team-1');
});

test('an issue payload builds Linear input without unset fields', function () {
    $payload = new IssuePayload(
        new Destination(teamId: 'team-1', priority: 0, assigneeId: 'user-1'),
        title: 'Help',
        description: '',
    );

    expect($payload->hasContent())->toBeTrue()
        ->and($payload->toIssueInput('issue-uuid'))->toBe([
            'id' => 'issue-uuid',
            'teamId' => 'team-1',
            'title' => 'Help',
            'description' => '',
            'priority' => 0,
            'assigneeId' => 'user-1',
        ]);
});

test('an issue payload without content gains it immutably', function () {
    $payload = new IssuePayload(new Destination('team-1'));
    $completed = $payload->withContent('Title', 'Body');

    expect($payload->hasContent())->toBeFalse()
        ->and($completed->hasContent())->toBeTrue()
        ->and($completed->destination)->toBe($payload->destination)
        ->and($payload->toArray())->not->toHaveKeys(['title', 'description'])
        ->and($completed->toArray())->toMatchArray(['title' => 'Title', 'description' => 'Body']);
});

test('an issue payload is restored from stored JSON', function () {
    $payload = IssuePayload::fromArray(['team_id' => 'team-1', 'title' => '', 'description' => 'Body']);

    expect($payload->title)->toBeNull()->and($payload->description)->toBe('Body')->and($payload->hasContent())->toBeFalse()
        ->and(IssuePayload::fromArray(['team_id' => 't'])->description)->toBeNull();
});

test('team options check membership of destination IDs', function () {
    $options = new TeamOptions(
        team: new Team('team-1', 'Support', 'SUP'),
        projects: [new Project('project-1', 'Inbox')],
        states: [new WorkflowState('state-1', 'Triage', 'triage')],
        labels: [new Label('label-1', 'Bug', null)],
        members: [new Member('user-1', 'Ada')],
    );

    expect($options->hasProject('project-1'))->toBeTrue()
        ->and($options->hasProject(null))->toBeTrue()
        ->and($options->hasProject('project-x'))->toBeFalse()
        ->and($options->hasState('state-1'))->toBeTrue()
        ->and($options->hasState('state-x'))->toBeFalse()
        ->and($options->hasMember('user-1'))->toBeTrue()
        ->and($options->hasMember('user-x'))->toBeFalse()
        ->and($options->hasLabels(['label-1']))->toBeTrue()
        ->and($options->hasLabels([]))->toBeTrue()
        ->and($options->hasLabels(['label-1', 'label-x']))->toBeFalse()
        ->and($options->jsonSerialize())->toBe([
            'states' => [['id' => 'state-1', 'name' => 'Triage', 'type' => 'triage']],
            'projects' => [['id' => 'project-1', 'name' => 'Inbox']],
            'members' => [['id' => 'user-1', 'name' => 'Ada']],
            'labels' => [['id' => 'label-1', 'name' => 'Bug', 'color' => null]],
        ]);
});

test('API nodes are read defensively', function () {
    expect(Issue::fromArray(['id' => 'i', 'identifier' => 'SUP-1', 'url' => 'u']))->toEqual(new Issue('i', 'SUP-1', 'u'))
        ->and(Comment::fromArray(['id' => 'c']))->toEqual(new Comment('c', null))
        ->and(Comment::fromArray(['id' => 'c', 'url' => 'u']))->url->toBe('u')
        ->and(Organization::fromArray(['id' => 'o', 'name' => 'Acme']))->toEqual(new Organization('o', 'Acme', null))
        ->and(Team::fromArray(['id' => 't', 'name' => 'N', 'key' => 'K']))->toEqual(new Team('t', 'N', 'K'))
        ->and(Viewer::fromArray(['id' => 'v', 'organization' => ['id' => 'o', 'name' => 'Acme']]))
        ->toEqual(new Viewer('v', null, null, new Organization('o', 'Acme', null)))
        ->and((new ResolvedDestination(new Destination('t'), new Team('t', 'N', 'K')))->team->key)->toBe('K');
});

test('the JSON helpers narrow untyped values', function () {
    expect(Json::string(5))->toBe('5')
        ->and(Json::string(null, 'fallback'))->toBe('fallback')
        ->and(Json::string([]))->toBe('')
        ->and(Json::nullableString(''))->toBeNull()
        ->and(Json::nullableString(5))->toBeNull()
        ->and(Json::nullableString('x'))->toBe('x')
        ->and(Json::integer('7'))->toBe(7)
        ->and(Json::integer('x'))->toBeNull()
        ->and(Json::map('x'))->toBe([])
        ->and(Json::map([5 => 'a']))->toBe(['5' => 'a'])
        ->and(Json::rows([['a' => 1], 'x', ['b' => 2]]))->toBe([['a' => 1], ['b' => 2]])
        ->and(Json::rows(null))->toBe([])
        ->and(Json::strings(['a', 1, [], null, 2.5]))->toBe(['a', '1', '2.5']);
});

test('API exceptions say whether to retry or reconnect', function () {
    expect(LinearApiException::notConnected())
        ->reason->toBe(LinearApiException::AUTHENTICATION)
        ->requiresReconnect()->toBeTrue()
        ->isRetryable()->toBeFalse()
        ->and(LinearApiException::notConfigured())->reason->toBe(LinearApiException::INVALID_REQUEST)
        ->and((new LinearApiException('x', LinearApiException::TRANSIENT))->isRetryable())->toBeTrue()
        ->and((new LinearApiException('x', LinearApiException::RATE_LIMITED))->isRetryable())->toBeTrue()
        ->and((new LinearApiException('x', LinearApiException::FORBIDDEN))->isRetryable())->toBeFalse();
});
