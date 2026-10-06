<?php

declare(strict_types=1);

namespace Dniccum\Linear\Testing;

use Closure;
use Dniccum\Linear\Data\IssuePayload;
use Dniccum\Linear\Data\Team;
use Dniccum\Linear\Data\TeamOptions;
use Dniccum\Linear\Data\Tokens;
use Dniccum\Linear\Data\Viewer;
use Dniccum\Linear\Exceptions\LinearApiException;
use Dniccum\Linear\Services\LinearClient;
use Dniccum\Linear\Services\LinearOAuth;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use PHPUnit\Framework\Assert;

/**
 * What `Linear::fake()` returns: the Linear API replaced by in-memory fakes,
 * plus the arranging and assertion helpers for your tests.
 *
 *     $fake = Linear::fake();
 *     $fake->withTeams([new Team('team-9', 'Bugs', 'BUG')]);
 *
 *     $ticket->sendToLinear();
 *
 *     $fake->assertIssueCreated(fn (IssuePayload $payload) => $payload->destination->teamId === 'team-9');
 *
 * The fakes are bound in the container, so everything that talks to Linear (the
 * model traits, jobs, actions and controllers) uses them. Run the queue
 * synchronously (the default in tests) or call the jobs yourself.
 */
final class LinearFake
{
    public function __construct(
        public readonly FakeLinearClient $client,
        public readonly FakeLinearOAuth $oauth,
    ) {}

    /**
     * Bind the fakes into the container. Also fills in OAuth client
     * credentials when none are configured, so the OAuth flow is available.
     */
    public static function install(Application $app): self
    {
        $config = $app->make('config');

        if (blank($config->get('linear.client_id')) || blank($config->get('linear.client_secret'))) {
            $config->set('linear.client_id', 'fake-client-id');
            $config->set('linear.client_secret', 'fake-client-secret');
        }

        $http = $app->make(HttpFactory::class);
        $oauth = new FakeLinearOAuth($http);
        $client = new FakeLinearClient($http, $oauth);

        $app->instance(LinearOAuth::class, $oauth);
        $app->instance(LinearClient::class, $client);

        return new self($client, $oauth);
    }

    /**
     * @param  list<Team>  $teams
     */
    public function withTeams(array $teams): self
    {
        $this->client->withTeams($teams);

        return $this;
    }

    public function withTeamOptions(TeamOptions $options): self
    {
        $this->client->withTeamOptions($options);

        return $this;
    }

    /**
     * The workspace and user that API key validation and OAuth callbacks
     * resolve to.
     */
    public function withViewer(Viewer $viewer): self
    {
        $this->client->withViewer($viewer);

        return $this;
    }

    public function withTokens(Tokens $tokens): self
    {
        $this->oauth->withTokens($tokens);

        return $this;
    }

    /**
     * Make the next call(s) of an API operation throw, to exercise failure
     * handling: "viewer", "teams", "teamOptions", "createIssue", "findIssue",
     * "createComment" or "findComment".
     */
    public function failWith(string $operation, LinearApiException $exception, int $times = 1): self
    {
        $this->client->failWith($operation, $exception, $times);

        return $this;
    }

    /**
     * Every issue sent to Linear, with its client-generated ID.
     *
     * @return list<array{issueId: string, payload: IssuePayload}>
     */
    public function createdIssues(): array
    {
        return $this->client->createdIssues();
    }

    /**
     * Every comment sent to Linear, with the ID of the Linear issue.
     *
     * @return list<array{issueId: string, body: string}>
     */
    public function postedComments(): array
    {
        return $this->client->postedComments();
    }

    /**
     * Assert an issue was created, optionally one the callback accepts. The
     * callback receives the issue payload and the client-generated issue ID.
     *
     * @param  (Closure(IssuePayload, string): bool)|null  $callback
     */
    public function assertIssueCreated(?Closure $callback = null): self
    {
        $matching = array_filter(
            $this->createdIssues(),
            fn (array $issue): bool => $callback === null || $callback($issue['payload'], $issue['issueId']),
        );

        Assert::assertNotEmpty($matching, 'No matching Linear issue was created.');

        return $this;
    }

    public function assertIssueCreatedCount(int $count): self
    {
        Assert::assertCount($count, $this->createdIssues(), "Expected {$count} Linear issue(s) to be created.");

        return $this;
    }

    public function assertNoIssueCreated(): self
    {
        return $this->assertIssueCreatedCount(0);
    }

    /**
     * Assert a comment was posted, optionally one the callback accepts. The
     * callback receives the comment body and the Linear issue ID.
     *
     * @param  (Closure(string, string): bool)|null  $callback
     */
    public function assertCommentPosted(?Closure $callback = null): self
    {
        $matching = array_filter(
            $this->postedComments(),
            fn (array $comment): bool => $callback === null || $callback($comment['body'], $comment['issueId']),
        );

        Assert::assertNotEmpty($matching, 'No matching Linear comment was posted.');

        return $this;
    }

    public function assertNoCommentPosted(): self
    {
        Assert::assertCount(0, $this->postedComments(), 'Expected no Linear comment to be posted.');

        return $this;
    }

    /**
     * Assert the API was not called at all.
     */
    public function assertNothingSent(): self
    {
        Assert::assertCount(0, $this->client->calls(), 'Expected Linear not to be called.');

        return $this;
    }
}
