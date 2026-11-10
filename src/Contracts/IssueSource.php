<?php

declare(strict_types=1);

namespace Dniccum\Linear\Contracts;

/**
 * A record that becomes (or is mirrored onto) a Linear issue: a support
 * request, an order, a bug report.
 */
interface IssueSource
{
    /**
     * A stable name for the record's kind (a morph alias, an entity name).
     */
    public function sourceType(): string;

    public function sourceId(): int|string;

    /**
     * Who owns the connection the issue is filed through; null opts the
     * record out.
     */
    public function owner(): ?IssueOwner;

    /**
     * A destination that replaces the owner's for this record.
     */
    public function destinationOverride(): ?DestinationSettings;

    /**
     * The issue title. Linear caps titles at 255 characters.
     */
    public function title(): string;

    /**
     * The issue description, as markdown.
     */
    public function description(): string;

    /**
     * The comment posted for a lifecycle event ("updated" or "deleted").
     *
     * @param  array<string, mixed>  $context  For "updated", `changes` maps each changed attribute to its new value.
     */
    public function comment(string $event, array $context = []): string;

    /**
     * The attributes that changed in the update being handled, mapped to
     * their new values, without bookkeeping columns such as `updated_at`.
     *
     * @return array<string, mixed>
     */
    public function changes(): array;
}
