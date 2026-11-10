<?php

declare(strict_types=1);

namespace Dniccum\Linear\Testing\InMemory;

use Dniccum\Linear\Contracts\CommentDelivery;
use Dniccum\Linear\Contracts\Connection;
use Dniccum\Linear\Contracts\IssueLink;
use Dniccum\Linear\Contracts\IssueOwner;
use Dniccum\Linear\Contracts\IssueSource;
use Dniccum\Linear\Contracts\LinearStore;
use Dniccum\Linear\Data\IssuePayload;
use Dniccum\Linear\Enums\LinearIssueSource;
use Dniccum\Linear\Enums\LinearSyncStatus;
use Dniccum\Linear\Exceptions\DuplicateIssueLinkException;
use InvalidArgumentException;

/**
 * A {@see LinearStore} that keeps everything in arrays: for tests, and as a
 * compact reference for writing your own on top of Doctrine or plain SQL.
 *
 * Links and deliveries it did not create (or that were created for sources that
 * are not in-memory ones) are rejected.
 */
final class InMemoryStore implements LinearStore
{
    /**
     * @var array<int, InMemoryIssueLink>
     */
    public array $links = [];

    /**
     * @var array<int, InMemoryCommentDelivery>
     */
    public array $deliveries = [];

    /**
     * Deliveries by origin, so each origin is delivered once.
     *
     * @var array<string, InMemoryCommentDelivery>
     */
    private array $byOrigin = [];

    public function findLink(int|string $id): ?IssueLink
    {
        return $this->links[(int) $id] ?? null;
    }

    public function linkFor(IssueSource $source): ?IssueLink
    {
        foreach ($this->links as $link) {
            if ($link->source->sourceType() === $source->sourceType() && $link->source->sourceId() === $source->sourceId()) {
                return $link;
            }
        }

        return null;
    }

    public function createLink(IssueSource $source, IssueOwner $owner, Connection $connection, LinearIssueSource $kind, string $issueId, IssuePayload $payload): IssueLink
    {
        if ($this->linkFor($source) !== null) {
            throw new DuplicateIssueLinkException;
        }

        $id = count($this->links) + 1;

        return $this->links[$id] = new InMemoryIssueLink($id, $source, $owner, $connection->organizationId(), $kind, $issueId, $payload);
    }

    public function sourceFor(IssueLink $link): IssueSource
    {
        return self::link($link)->source;
    }

    public function connectionFor(IssueLink $link): ?Connection
    {
        return self::link($link)->owner->connection();
    }

    public function findDelivery(int|string $id): ?CommentDelivery
    {
        return $this->deliveries[(int) $id] ?? null;
    }

    public function createDelivery(IssueLink $link, string $commentId, string $body, ?IssueSource $origin): CommentDelivery
    {
        $key = $origin === null ? null : $origin->sourceType().'#'.$origin->sourceId();

        if ($key !== null && isset($this->byOrigin[$key])) {
            $this->byOrigin[$key]->justQueued = false;

            return $this->byOrigin[$key];
        }

        $id = count($this->deliveries) + 1;
        $delivery = $this->deliveries[$id] = new InMemoryCommentDelivery($id, $link, $commentId, $body);

        if ($key !== null) {
            $this->byOrigin[$key] = $delivery;
        }

        return $delivery;
    }

    public function deliveries(IssueLink $link, LinearSyncStatus $status): array
    {
        return array_values(array_filter(
            $this->deliveries,
            fn (InMemoryCommentDelivery $delivery): bool => $delivery->link === $link && $delivery->status === $status,
        ));
    }

    private static function link(IssueLink $link): InMemoryIssueLink
    {
        return $link instanceof InMemoryIssueLink
            ? $link
            : throw new InvalidArgumentException('The in-memory store only handles its own links.');
    }
}
