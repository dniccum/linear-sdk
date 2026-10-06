<?php

declare(strict_types=1);

namespace Dniccum\Linear\Contracts;

use Dniccum\Linear\Services\IssueComposer;

/**
 * Implement on a model to take full control of the Linear issue and comments
 * composed for it.
 *
 * The `CreatesLinearIssues` trait already provides working defaults for all
 * three methods (built by {@see IssueComposer}), so
 * override only the ones you care about; declaring `implements
 * ComposesLinearIssue` is optional and only documents the intent.
 */
interface ComposesLinearIssue
{
    /**
     * The issue title. Linear caps titles at 255 characters; longer ones are
     * truncated.
     */
    public function linearTitle(): string;

    /**
     * The issue description, as Linear-flavoured markdown.
     */
    public function linearDescription(): string;

    /**
     * The comment posted to the linked issue for a lifecycle event.
     *
     * @param  string  $event  "updated" or "deleted"
     * @param  array<string, mixed>  $context  For "updated", `changes` maps each changed attribute to its new value.
     */
    public function linearComment(string $event, array $context = []): string;
}
