<?php

declare(strict_types=1);

namespace Dniccum\Linear\Exceptions;

use RuntimeException;

/**
 * A chosen team, project, status, label or assignee is not available in the
 * connected workspace.
 */
final class InvalidDestinationException extends RuntimeException
{
    /**
     * @param  array<string, string>  $errors  Messages keyed by the camelCase field name (teamId, projectId, ...).
     */
    public function __construct(
        public readonly array $errors,
    ) {
        parent::__construct(implode(' ', $errors));
    }
}
