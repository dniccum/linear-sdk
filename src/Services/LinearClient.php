<?php

declare(strict_types=1);

namespace Dniccum\Linear\Services;

use Dniccum\Linear\Data\Comment;
use Dniccum\Linear\Data\Issue;
use Dniccum\Linear\Data\IssuePayload;
use Dniccum\Linear\Data\Label;
use Dniccum\Linear\Data\Member;
use Dniccum\Linear\Data\Project;
use Dniccum\Linear\Data\Team;
use Dniccum\Linear\Data\TeamOptions;
use Dniccum\Linear\Data\Viewer;
use Dniccum\Linear\Data\WorkflowState;
use Dniccum\Linear\Enums\LinearAuthMode;
use Dniccum\Linear\Enums\LinearConnectionStatus;
use Dniccum\Linear\Exceptions\LinearApiException;
use Dniccum\Linear\Models\LinearConnection;
use Dniccum\Linear\Support\Json;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Linear GraphQL API client scoped to a connection.
 *
 * Every call made through a connection refreshes an OAuth access token when
 * it is about to expire, and flags the connection for reconnection when
 * Linear rejects the credentials outright. Personal API keys are sent as the
 * raw Authorization header and never refreshed.
 */
class LinearClient
{
    public function __construct(
        protected HttpFactory $http,
        protected LinearOAuth $oauth,
    ) {}

    /**
     * The authorizing user and their workspace, looked up with a raw
     * credential before a connection exists (during the OAuth callback, or
     * when validating an API key).
     *
     * @throws LinearApiException
     */
    public function viewer(string $token, LinearAuthMode $mode = LinearAuthMode::OAuth): Viewer
    {
        $data = $this->send($token, $mode, <<<'GRAPHQL'
            query Viewer {
              viewer { id name email organization { id name urlKey } }
            }
            GRAPHQL);

        return Viewer::fromArray(Json::map($data['viewer'] ?? null));
    }

    /**
     * @return list<Team>
     *
     * @throws LinearApiException
     */
    public function teams(LinearConnection $connection): array
    {
        $teams = $this->paginate($connection, <<<'GRAPHQL'
            query Teams($after: String) {
              teams(first: 250, after: $after) {
                nodes { id name key }
                pageInfo { hasNextPage endCursor }
              }
            }
            GRAPHQL, [], 'teams');

        return array_map(fn (array $team): Team => Team::fromArray($team), self::sortedByName($teams));
    }

    /**
     * The selectable destinations inside a team: open projects, workflow
     * statuses, assignable labels (team and workspace-wide) and active
     * members.
     *
     * Throws an invalid-request error when the team does not exist or the
     * connection can no longer see it.
     *
     * @throws LinearApiException
     */
    public function teamOptions(LinearConnection $connection, string $teamId): TeamOptions
    {
        $data = $this->query($connection, <<<'GRAPHQL'
            query TeamOptions($teamId: String!) {
              team(id: $teamId) {
                id name key
                states(first: 250) { nodes { id name type position } }
              }
            }
            GRAPHQL, ['teamId' => $teamId]);

        $team = $data['team'] ?? null;

        if (! is_array($team)) {
            throw new LinearApiException('That Linear team is no longer available to your connection.', LinearApiException::INVALID_REQUEST);
        }

        $team = Json::map($team);

        $projects = $this->paginate($connection, <<<'GRAPHQL'
            query TeamProjects($teamId: String!, $after: String) {
              team(id: $teamId) {
                projects(first: 250, after: $after) {
                  nodes { id name completedAt canceledAt }
                  pageInfo { hasNextPage endCursor }
                }
              }
            }
            GRAPHQL, ['teamId' => $teamId], 'team.projects');

        $members = $this->paginate($connection, <<<'GRAPHQL'
            query TeamMembers($teamId: String!, $after: String) {
              team(id: $teamId) {
                members(first: 250, after: $after) {
                  nodes { id name displayName active }
                  pageInfo { hasNextPage endCursor }
                }
              }
            }
            GRAPHQL, ['teamId' => $teamId], 'team.members');

        // The issueLabels team filter compares against an ID, so unlike the
        // other operations this one declares $teamId as ID!; declaring it
        // String! makes Linear reject the query.
        $labels = $this->paginate($connection, <<<'GRAPHQL'
            query TeamLabels($teamId: ID!, $after: String) {
              issueLabels(first: 250, after: $after, filter: { or: [{ team: { id: { eq: $teamId } } }, { team: { null: true } }] }) {
                nodes { id name color isGroup }
                pageInfo { hasNextPage endCursor }
              }
            }
            GRAPHQL, ['teamId' => $teamId], 'issueLabels');

        $states = Json::rows(data_get($team, 'states.nodes'));
        usort($states, fn (array $a, array $b): int => Json::integer($a['position'] ?? null) <=> Json::integer($b['position'] ?? null));

        $openProjects = array_values(array_filter(
            $projects,
            fn (array $project): bool => ($project['completedAt'] ?? null) === null && ($project['canceledAt'] ?? null) === null,
        ));

        // Group labels only organize their children; Linear refuses to apply
        // them to an issue directly.
        $assignableLabels = array_values(array_filter($labels, fn (array $label): bool => ! (bool) ($label['isGroup'] ?? false)));

        $activeMembers = [];

        foreach ($members as $member) {
            if ((bool) ($member['active'] ?? true)) {
                $activeMembers[] = [
                    'id' => Json::string($member['id'] ?? null),
                    'name' => Json::nullableString($member['displayName'] ?? null) ?? Json::string($member['name'] ?? null),
                ];
            }
        }

        return new TeamOptions(
            team: Team::fromArray($team),
            projects: array_map(
                fn (array $project): Project => new Project(Json::string($project['id'] ?? null), Json::string($project['name'] ?? null)),
                self::sortedByName($openProjects),
            ),
            states: array_map(
                fn (array $state): WorkflowState => new WorkflowState(
                    Json::string($state['id'] ?? null),
                    Json::string($state['name'] ?? null),
                    Json::string($state['type'] ?? null),
                ),
                $states,
            ),
            labels: array_map(
                fn (array $label): Label => new Label(
                    Json::string($label['id'] ?? null),
                    Json::string($label['name'] ?? null),
                    Json::nullableString($label['color'] ?? null),
                ),
                self::sortedByName($assignableLabels),
            ),
            members: array_map(
                fn (array $member): Member => new Member(Json::string($member['id'] ?? null), Json::string($member['name'] ?? null)),
                self::sortedByName($activeMembers),
            ),
        );
    }

    /**
     * Create an issue with a client-generated ID, so a retry can look it up
     * instead of creating a duplicate.
     *
     * @throws LinearApiException
     */
    public function createIssue(LinearConnection $connection, string $issueId, IssuePayload $payload): Issue
    {
        $data = $this->query($connection, <<<'GRAPHQL'
            mutation CreateIssue($input: IssueCreateInput!) {
              issueCreate(input: $input) { success issue { id identifier url } }
            }
            GRAPHQL, ['input' => $payload->toIssueInput($issueId)]);

        return Issue::fromArray($this->mutationResult($data['issueCreate'] ?? null, 'issue', 'create the Linear issue'));
    }

    /**
     * Look up an issue by ID, returning null when it does not exist.
     *
     * @throws LinearApiException
     */
    public function findIssue(LinearConnection $connection, string $id): ?Issue
    {
        $issue = $this->find($connection, <<<'GRAPHQL'
            query FindIssue($id: String!) {
              issue(id: $id) { id identifier url }
            }
            GRAPHQL, $id, 'issue');

        return $issue === null ? null : Issue::fromArray($issue);
    }

    /**
     * Post a comment with a client-generated ID; see createIssue().
     *
     * @throws LinearApiException
     */
    public function createComment(LinearConnection $connection, string $commentId, string $issueId, string $body): Comment
    {
        $data = $this->query($connection, <<<'GRAPHQL'
            mutation CreateComment($input: CommentCreateInput!) {
              commentCreate(input: $input) { success comment { id url } }
            }
            GRAPHQL, ['input' => ['id' => $commentId, 'issueId' => $issueId, 'body' => $body]]);

        return Comment::fromArray($this->mutationResult($data['commentCreate'] ?? null, 'comment', 'post the Linear comment'));
    }

    /**
     * @throws LinearApiException
     */
    public function findComment(LinearConnection $connection, string $id): ?Comment
    {
        $comment = $this->find($connection, <<<'GRAPHQL'
            query FindComment($id: String!) {
              comment(id: $id) { id url }
            }
            GRAPHQL, $id, 'comment');

        return $comment === null ? null : Comment::fromArray($comment);
    }

    /**
     * Run a query on behalf of a connection, refreshing its OAuth token first
     * when needed and once more if Linear rejects the token anyway.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     *
     * @throws LinearApiException
     */
    public function query(LinearConnection $connection, string $query, array $variables = []): array
    {
        if (! $connection->isActive()) {
            throw new LinearApiException(
                'Your Linear connection needs to be reauthorized. Reconnect Linear to continue.',
                LinearApiException::AUTHENTICATION,
            );
        }

        if (! $connection->usesApiKey() && $connection->tokenExpiresSoon()) {
            $this->refreshToken($connection);
        }

        try {
            return $this->send($connection->access_token, $connection->auth_type, $query, $variables);
        } catch (LinearApiException $e) {
            if (! $e->requiresReconnect() || blank($connection->refresh_token)) {
                throw $this->recordFailure($connection, $e);
            }
        }

        // The token may have been revoked early or rotated by a concurrent
        // refresh; try exactly once more with a freshly refreshed token.
        $this->refreshToken($connection);

        try {
            return $this->send($connection->access_token, $connection->auth_type, $query, $variables);
        } catch (LinearApiException $e) {
            throw $this->recordFailure($connection, $e);
        }
    }

    /**
     * Collect every node of a paginated connection by following its cursor,
     * capped so a runaway workspace can't stall the request.
     *
     * @param  array<string, mixed>  $variables
     * @return list<array<string, mixed>>
     *
     * @throws LinearApiException
     */
    protected function paginate(LinearConnection $connection, string $query, array $variables, string $path, int $maxPages = 20): array
    {
        $nodes = [];
        $after = null;

        for ($page = 0; $page < $maxPages; $page++) {
            $result = Json::map(data_get($this->query($connection, $query, [...$variables, 'after' => $after]), $path));
            $pageInfo = Json::map($result['pageInfo'] ?? null);
            $cursor = Json::nullableString($pageInfo['endCursor'] ?? null);

            array_push($nodes, ...Json::rows($result['nodes'] ?? null));

            if (! (bool) ($pageInfo['hasNextPage'] ?? false) || $cursor === null) {
                break;
            }

            $after = $cursor;
        }

        return $nodes;
    }

    /**
     * Exchange the refresh token for a new access token, serialized per
     * connection so concurrent jobs don't burn the same refresh token.
     *
     * @throws LinearApiException
     */
    protected function refreshToken(LinearConnection $connection): void
    {
        Cache::lock("linear-connection-refresh:{$connection->id}", 30)->block(15, function () use ($connection): void {
            $previous = $connection->access_token;
            $connection->refresh();

            // Another worker refreshed while we waited for the lock.
            if ($connection->access_token !== $previous && ! $connection->tokenExpiresSoon()) {
                return;
            }

            if (blank($connection->refresh_token)) {
                throw $this->recordFailure($connection, new LinearApiException(
                    'Your Linear authorization has expired. Reconnect Linear to continue.',
                    LinearApiException::AUTHENTICATION,
                ));
            }

            try {
                $tokens = $this->oauth->refresh((string) $connection->refresh_token);
            } catch (LinearApiException $e) {
                throw $this->recordFailure($connection, $e);
            }

            $connection->forceFill([
                'access_token' => $tokens->accessToken,
                'refresh_token' => $tokens->refreshToken ?? $connection->refresh_token,
                'token_expires_at' => $tokens->expiresAt(),
                'status' => LinearConnectionStatus::Active,
            ])->save();
        });
    }

    /**
     * Persist a failure on the connection so the settings page can show it,
     * flagging authentication failures for reconnection.
     */
    protected function recordFailure(LinearConnection $connection, LinearApiException $e): LinearApiException
    {
        if ($e->requiresReconnect()) {
            $connection->markNeedsReconnect($e->getMessage());
        } elseif ($e->reason === LinearApiException::FORBIDDEN) {
            $connection->markFailed($e->getMessage());
        }

        return $e;
    }

    /**
     * @return array<string, mixed>|null
     *
     * @throws LinearApiException
     */
    protected function find(LinearConnection $connection, string $query, string $id, string $field): ?array
    {
        try {
            $data = $this->query($connection, $query, ['id' => $id]);
        } catch (LinearApiException $e) {
            if ($e->reason === LinearApiException::INVALID_REQUEST && preg_match('/not found|could not find/i', $e->getMessage()) === 1) {
                return null;
            }

            throw $e;
        }

        $entity = $data[$field] ?? null;

        return is_array($entity) ? Json::map($entity) : null;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws LinearApiException
     */
    protected function mutationResult(mixed $payload, string $field, string $action): array
    {
        $payload = Json::map($payload);
        $entity = $payload[$field] ?? null;

        if (($payload['success'] ?? false) !== true || ! is_array($entity)) {
            throw new LinearApiException("Linear did not {$action}.", LinearApiException::TRANSIENT);
        }

        return Json::map($entity);
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     *
     * @throws LinearApiException
     */
    protected function send(string $token, LinearAuthMode $mode, string $query, array $variables = []): array
    {
        try {
            $response = $this->request($token, $mode)
                ->post(rtrim(config()->string('linear.api_url'), '/').'/graphql', $variables === []
                    ? ['query' => $query]
                    : ['query' => $query, 'variables' => $variables]);
        } catch (Throwable $e) {
            Log::warning('Linear API request failed.', ['exception' => $e->getMessage()]);

            throw new LinearApiException('Could not reach Linear. Please try again.', LinearApiException::TRANSIENT);
        }

        $errors = $response->json('errors');

        if (is_array($errors) && $errors !== []) {
            throw $this->classify($response, $errors);
        }

        if (! $response->successful()) {
            throw $this->classify($response, []);
        }

        $data = $response->json('data');

        if (! is_array($data)) {
            throw new LinearApiException('Linear returned an unexpected response.', LinearApiException::TRANSIENT);
        }

        return Json::map($data);
    }

    /**
     * OAuth tokens are Bearer credentials; a personal API key goes in the
     * Authorization header as-is.
     */
    protected function request(string $token, LinearAuthMode $mode): PendingRequest
    {
        $request = $mode === LinearAuthMode::ApiKey
            ? $this->http->withHeaders(['Authorization' => $token])
            : $this->http->withToken($token);

        return $request->acceptJson()->timeout(15);
    }

    /**
     * @param  array<array-key, mixed>  $errors
     */
    protected function classify(Response $response, array $errors): LinearApiException
    {
        $error = Json::map($errors[0] ?? null);
        $code = strtoupper(Json::string(data_get($error, 'extensions.code')));
        $type = strtolower(Json::string(data_get($error, 'extensions.type')));
        $message = Json::nullableString(data_get($error, 'extensions.userPresentableMessage')) ?? Json::string($error['message'] ?? null);

        Log::warning('Linear API returned an error.', [
            'status' => $response->status(),
            'code' => $code,
            'message' => $error['message'] ?? null,
        ]);

        if ($response->status() === 401 || $code === 'AUTHENTICATION_ERROR' || $type === 'authentication error') {
            return new LinearApiException(
                'Linear rejected your authorization. Reconnect Linear to continue.',
                LinearApiException::AUTHENTICATION,
            );
        }

        if ($code === 'FORBIDDEN' || $type === 'forbidden') {
            return new LinearApiException(
                $message !== '' ? "Linear denied access: {$message}" : 'Your Linear connection does not have permission for this action.',
                LinearApiException::FORBIDDEN,
            );
        }

        if ($response->status() === 429 || $code === 'RATELIMITED' || $type === 'ratelimited') {
            return new LinearApiException('Linear is rate limiting requests. We will retry shortly.', LinearApiException::RATE_LIMITED);
        }

        if ($response->serverError() || $code === 'INTERNAL_ERROR') {
            return new LinearApiException('Linear is temporarily unavailable. We will retry shortly.', LinearApiException::TRANSIENT);
        }

        return new LinearApiException(
            $message !== '' ? $message : 'Linear rejected the request.',
            LinearApiException::INVALID_REQUEST,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function sortedByName(array $rows): array
    {
        usort($rows, fn (array $a, array $b): int => strnatcasecmp(Json::string($a['name'] ?? null), Json::string($b['name'] ?? null)));

        return $rows;
    }
}
