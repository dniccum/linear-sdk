<?php

declare(strict_types=1);

namespace Dniccum\Linear\Laravel;

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
use Dniccum\Linear\Models\LinearCommentDelivery;
use Dniccum\Linear\Models\LinearIssueLink;
use Dniccum\Linear\Services\IssueComposer;
use Dniccum\Linear\Support\ModelHooks;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

/**
 * Stores issue links and comment deliveries in the package's Eloquent tables.
 */
final readonly class EloquentStore implements LinearStore
{
    public function __construct(
        private IssueComposer $composer,
    ) {}

    public function findLink(int|string $id): ?IssueLink
    {
        return LinearIssueLink::query()->find($id);
    }

    public function linkFor(IssueSource $source): ?IssueLink
    {
        return $this->query($source)->first();
    }

    public function createLink(IssueSource $source, IssueOwner $owner, Connection $connection, LinearIssueSource $kind, string $issueId, IssuePayload $payload): IssueLink
    {
        try {
            return LinearIssueLink::query()->create([
                'linkable_type' => $source->sourceType(),
                'linkable_id' => $source->sourceId(),
                'owner_type' => $owner->ownerType(),
                'owner_id' => $owner->ownerId(),
                'connection_id' => $connection->connectionId(),
                'linear_organization_id' => $connection->organizationId(),
                'source' => $kind,
                'linear_issue_id' => $issueId,
                'payload' => $payload,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new DuplicateIssueLinkException;
        }
    }

    public function sourceFor(IssueLink $link): ?IssueSource
    {
        $source = self::link($link)->linkable;

        return $source === null ? null : new ModelSource($source, $this->composer);
    }

    public function connectionFor(IssueLink $link): ?Connection
    {
        $owner = self::link($link)->owner;

        return $owner === null ? null : ModelHooks::connection($owner);
    }

    public function findDelivery(int|string $id): ?CommentDelivery
    {
        return LinearCommentDelivery::query()->find($id);
    }

    public function createDelivery(IssueLink $link, string $commentId, string $body, ?IssueSource $origin): CommentDelivery
    {
        $attributes = [
            'linear_issue_link_id' => self::link($link)->getKey(),
            'linear_comment_id' => $commentId,
            'body' => $body,
        ];

        return $origin === null
            ? LinearCommentDelivery::query()->create($attributes)
            : LinearCommentDelivery::query()->firstOrCreate(
                ['source_type' => $origin->sourceType(), 'source_id' => $origin->sourceId()],
                $attributes,
            );
    }

    public function deliveries(IssueLink $link, LinearSyncStatus $status): array
    {
        return array_values(self::link($link)->commentDeliveries()->where('status', $status)->orderBy('id')->get()->all());
    }

    /**
     * @return Builder<LinearIssueLink>
     */
    private function query(IssueSource $source): Builder
    {
        return LinearIssueLink::query()
            ->where('linkable_type', $source->sourceType())
            ->where('linkable_id', $source->sourceId());
    }

    private static function link(IssueLink $link): LinearIssueLink
    {
        return $link instanceof LinearIssueLink
            ? $link
            : throw new InvalidArgumentException('The Eloquent store only handles '.LinearIssueLink::class.' links.');
    }
}
