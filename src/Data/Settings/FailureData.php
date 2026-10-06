<?php

declare(strict_types=1);

namespace Dniccum\Linear\Data\Settings;

use Dniccum\Linear\Data\Data;
use Dniccum\Linear\Enums\LinearSyncFailureKind;
use Dniccum\Linear\Models\LinearCommentDelivery;
use Dniccum\Linear\Models\LinearIssueLink;
use Illuminate\Support\Str;

/**
 * An issue or comment that could not be sent, listed on the configuration
 * page so it can be retried.
 */
final readonly class FailureData extends Data
{
    public function __construct(
        public string $id,
        public LinearSyncFailureKind $kind,
        public string $subject,
        public ?string $message,
        public int $attempts,
        public ?string $occurredAt,
        public string $linkId,
    ) {}

    public static function fromIssueLink(LinearIssueLink $link): self
    {
        return new self(
            id: "issue-{$link->id}",
            kind: LinearSyncFailureKind::Issue,
            subject: self::subject($link),
            message: $link->last_error,
            attempts: $link->attempts,
            occurredAt: $link->updated_at?->toIso8601String(),
            linkId: (string) $link->id,
        );
    }

    public static function fromCommentDelivery(LinearCommentDelivery $delivery): self
    {
        return new self(
            id: "comment-{$delivery->id}",
            kind: LinearSyncFailureKind::Comment,
            subject: self::subject($delivery->issueLink),
            message: $delivery->last_error,
            attempts: $delivery->attempts,
            occurredAt: $delivery->updated_at?->toIso8601String(),
            linkId: (string) $delivery->linear_issue_link_id,
        );
    }

    /**
     * @return array{id: string, kind: string, subject: string, message: ?string, attempts: int, occurredAt: ?string, linkId: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'subject' => $this->subject,
            'message' => $this->message,
            'attempts' => $this->attempts,
            'occurredAt' => $this->occurredAt,
            'linkId' => $this->linkId,
        ];
    }

    /**
     * What the failure is about: the issue title once it was composed, else
     * "Model #key".
     */
    private static function subject(LinearIssueLink $link): string
    {
        return $link->payload->title ?? Str::headline(class_basename($link->linkable_type)).' #'.$link->linkable_id;
    }
}
