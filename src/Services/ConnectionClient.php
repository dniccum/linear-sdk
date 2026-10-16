<?php

declare(strict_types=1);

namespace Dniccum\Linear\Services;

use Dniccum\Linear\Contracts\Connection;
use Dniccum\Linear\Data\Comment;
use Dniccum\Linear\Data\Issue;
use Dniccum\Linear\Data\IssuePayload;
use Dniccum\Linear\Data\Team;
use Dniccum\Linear\Data\TeamOptions;
use Dniccum\Linear\Exceptions\LinearApiException;

/**
 * A {@see LinearClient} bound to one connection, as returned by
 * `$owner->linearClient()` and `Linear::client($owner)`.
 *
 * In Laravel it delegates to the container's LinearClient, so `Linear::fake()`
 * applies.
 */
final readonly class ConnectionClient
{
    public function __construct(
        public Connection $connection,
        private LinearClient $client,
    ) {}

    /**
     * @return list<Team>
     *
     * @throws LinearApiException
     */
    public function teams(): array
    {
        return $this->client->teams($this->connection);
    }

    /**
     * @throws LinearApiException
     */
    public function teamOptions(string $teamId): TeamOptions
    {
        return $this->client->teamOptions($this->connection, $teamId);
    }

    /**
     * @throws LinearApiException
     */
    public function createIssue(string $issueId, IssuePayload $payload): Issue
    {
        return $this->client->createIssue($this->connection, $issueId, $payload);
    }

    /**
     * @throws LinearApiException
     */
    public function findIssue(string $id): ?Issue
    {
        return $this->client->findIssue($this->connection, $id);
    }

    /**
     * @throws LinearApiException
     */
    public function createComment(string $commentId, string $issueId, string $body): Comment
    {
        return $this->client->createComment($this->connection, $commentId, $issueId, $body);
    }

    /**
     * @throws LinearApiException
     */
    public function findComment(string $id): ?Comment
    {
        return $this->client->findComment($this->connection, $id);
    }

    /**
     * Run a raw GraphQL query as the connection.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     *
     * @throws LinearApiException
     */
    public function query(string $query, array $variables = []): array
    {
        return $this->client->query($this->connection, $query, $variables);
    }
}
