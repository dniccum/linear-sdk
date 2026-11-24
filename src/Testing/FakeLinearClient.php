<?php

declare(strict_types=1);

namespace Dniccum\Linear\Testing;

use Dniccum\Linear\Contracts\Connection;
use Dniccum\Linear\Data\Comment;
use Dniccum\Linear\Data\Issue;
use Dniccum\Linear\Data\IssuePayload;
use Dniccum\Linear\Data\Label;
use Dniccum\Linear\Data\Member;
use Dniccum\Linear\Data\Organization;
use Dniccum\Linear\Data\Project;
use Dniccum\Linear\Data\Team;
use Dniccum\Linear\Data\TeamOptions;
use Dniccum\Linear\Data\Viewer;
use Dniccum\Linear\Data\WorkflowState;
use Dniccum\Linear\Enums\LinearAuthMode;
use Dniccum\Linear\Exceptions\LinearApiException;
use Dniccum\Linear\LinearConfig;
use Dniccum\Linear\Services\LinearClient;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * An in-memory {@see LinearClient}: it records every call, answers from canned
 * data, and can be told to fail. Installed by `Linear::fake()`.
 *
 * Issues and comments it creates are remembered, so the find*() lookups the
 * sync service uses for idempotency behave as they would against Linear.
 */
final class FakeLinearClient extends LinearClient
{
    /**
     * @var list<Team>
     */
    private array $teams;

    /**
     * @var array<string, TeamOptions>
     */
    private array $options = [];

    private Viewer $viewer;

    /**
     * @var array<string, Issue>
     */
    private array $issues = [];

    /**
     * @var array<string, Comment>
     */
    private array $comments = [];

    /**
     * @var list<array{operation: string, arguments: array<string, mixed>}>
     */
    private array $calls = [];

    /**
     * @var list<array{issueId: string, payload: IssuePayload}>
     */
    private array $createdIssues = [];

    /**
     * @var list<array{issueId: string, body: string}>
     */
    private array $postedComments = [];

    /**
     * @var array<string, list<LinearApiException>>
     */
    private array $failures = [];

    public function __construct(FakeLinearOAuth $oauth, ?LinearConfig $config = null, ?HttpFactory $http = null)
    {
        parent::__construct($http ?? (new HttpFactory)->preventStrayRequests(), $oauth, $config ?? new LinearConfig);

        $this->teams = [new Team('team-1', 'Support', 'SUP', '#5e6ad2', '🛟')];
        $this->viewer = new Viewer('viewer-1', 'Ada Lovelace', 'ada@example.com', new Organization('org-1', 'Acme', 'acme'));
    }

    /**
     * @param  list<Team>  $teams
     */
    public function withTeams(array $teams): self
    {
        $this->teams = $teams;

        return $this;
    }

    /**
     * The options of one team. Teams without any answer with a small default
     * set (a project, two statuses, a label and a member).
     */
    public function withTeamOptions(TeamOptions $options): self
    {
        $this->options[$options->team->id] = $options;

        return $this;
    }

    public function withViewer(Viewer $viewer): self
    {
        $this->viewer = $viewer;

        return $this;
    }

    /**
     * Make the next call(s) of an operation ("viewer", "teams", "teamOptions",
     * "createIssue", "findIssue", "createComment", "findComment") throw.
     */
    public function failWith(string $operation, LinearApiException $exception, int $times = 1): self
    {
        for ($i = 0; $i < $times; $i++) {
            $this->failures[$operation][] = $exception;
        }

        return $this;
    }

    /**
     * Every call made so far, in order.
     *
     * @return list<array{operation: string, arguments: array<string, mixed>}>
     */
    public function calls(?string $operation = null): array
    {
        return array_values(array_filter(
            $this->calls,
            fn (array $call): bool => $operation === null || $call['operation'] === $operation,
        ));
    }

    /**
     * Every issue created, as client-generated ID and payload.
     *
     * @return list<array{issueId: string, payload: IssuePayload}>
     */
    public function createdIssues(): array
    {
        return $this->createdIssues;
    }

    /**
     * Every comment posted, as Linear issue ID and body.
     *
     * @return list<array{issueId: string, body: string}>
     */
    public function postedComments(): array
    {
        return $this->postedComments;
    }

    public function viewer(string $token, LinearAuthMode $mode = LinearAuthMode::OAuth): Viewer
    {
        $this->record('viewer', ['token' => $token, 'mode' => $mode]);

        return $this->viewer;
    }

    public function teams(Connection $connection): array
    {
        $this->record('teams', ['connection' => $connection]);

        return $this->teams;
    }

    public function teamOptions(Connection $connection, string $teamId): TeamOptions
    {
        $this->record('teamOptions', ['connection' => $connection, 'teamId' => $teamId]);

        if (isset($this->options[$teamId])) {
            return $this->options[$teamId];
        }

        foreach ($this->teams as $team) {
            if ($team->id === $teamId) {
                return $this->defaultOptions($team);
            }
        }

        throw new LinearApiException('That Linear team is no longer available to your connection.', LinearApiException::INVALID_REQUEST);
    }

    public function createIssue(Connection $connection, string $issueId, IssuePayload $payload): Issue
    {
        $this->record('createIssue', ['connection' => $connection, 'issueId' => $issueId, 'payload' => $payload]);

        $this->createdIssues[] = ['issueId' => $issueId, 'payload' => $payload];
        $number = count($this->issues) + 1;

        return $this->issues[$issueId] = new Issue($issueId, "FAKE-{$number}", "https://linear.app/fake/issue/FAKE-{$number}");
    }

    public function findIssue(Connection $connection, string $id): ?Issue
    {
        $this->record('findIssue', ['connection' => $connection, 'id' => $id]);

        return $this->issues[$id] ?? null;
    }

    public function createComment(Connection $connection, string $commentId, string $issueId, string $body): Comment
    {
        $this->record('createComment', ['connection' => $connection, 'commentId' => $commentId, 'issueId' => $issueId, 'body' => $body]);

        $this->postedComments[] = ['issueId' => $issueId, 'body' => $body];

        return $this->comments[$commentId] = new Comment($commentId, "https://linear.app/fake/comment/{$commentId}");
    }

    public function findComment(Connection $connection, string $id): ?Comment
    {
        $this->record('findComment', ['connection' => $connection, 'id' => $id]);

        return $this->comments[$id] ?? null;
    }

    /**
     * Raw GraphQL is not supported by the fake.
     */
    public function query(Connection $connection, string $query, array $variables = []): array
    {
        $this->record('query', ['connection' => $connection, 'query' => $query, 'variables' => $variables]);

        return [];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function record(string $operation, array $arguments): void
    {
        $this->calls[] = ['operation' => $operation, 'arguments' => $arguments];

        $failure = $this->failures[$operation][0] ?? null;

        if ($failure === null) {
            return;
        }

        array_shift($this->failures[$operation]);

        throw $failure;
    }

    private function defaultOptions(Team $team): TeamOptions
    {
        return new TeamOptions(
            team: $team,
            projects: [new Project('project-1', 'Inbox', '#4cb782', '📥')],
            states: [new WorkflowState('state-1', 'Triage', 'triage', '#bec2c8'), new WorkflowState('state-2', 'Todo', 'unstarted', '#e2e2e2')],
            labels: [new Label('label-1', 'Bug', '#eb5757')],
            members: [new Member('user-1', 'Ada Lovelace', null, 'AL', '#5e6ad2')],
        );
    }
}
