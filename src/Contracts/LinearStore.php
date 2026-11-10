<?php

declare(strict_types=1);

namespace Dniccum\Linear\Contracts;

use Dniccum\Linear\Data\IssuePayload;
use Dniccum\Linear\Enums\LinearIssueSource;
use Dniccum\Linear\Enums\LinearSyncStatus;
use Dniccum\Linear\Exceptions\DuplicateIssueLinkException;

/**
 * Where issue links and comment deliveries are stored, and how to get from a
 * stored link back to the records behind it.
 *
 * Laravel's implementation uses Eloquent; implement it with Doctrine, plain
 * PDO or anything else to run the sync elsewhere.
 */
interface LinearStore
{
    public function findLink(int|string $id): ?IssueLink;

    public function linkFor(IssueSource $source): ?IssueLink;

    /**
     * Create the link for a record with a new issue ID, status pending.
     *
     * @throws DuplicateIssueLinkException When the record already has one (another process won the race).
     */
    public function createLink(IssueSource $source, IssueOwner $owner, Connection $connection, LinearIssueSource $kind, string $issueId, IssuePayload $payload): IssueLink;

    /**
     * The record the link was created for, or null once it is gone.
     */
    public function sourceFor(IssueLink $link): ?IssueSource;

    /**
     * The link owner's connection as it is now, not as it was when the link
     * was created, so reconnecting the same workspace resumes syncing.
     */
    public function connectionFor(IssueLink $link): ?Connection;

    public function findDelivery(int|string $id): ?CommentDelivery;

    /**
     * Store a comment for a link. With an origin the call is idempotent: the
     * delivery made earlier for that origin is returned instead.
     */
    public function createDelivery(IssueLink $link, string $commentId, string $body, ?IssueSource $origin): CommentDelivery;

    /**
     * A link's deliveries in the given status, oldest first.
     *
     * @return list<CommentDelivery>
     */
    public function deliveries(IssueLink $link, LinearSyncStatus $status): array;
}
