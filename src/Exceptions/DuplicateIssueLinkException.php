<?php

declare(strict_types=1);

namespace Dniccum\Linear\Exceptions;

use RuntimeException;

/**
 * A record already has an issue link: another process linked it first.
 */
final class DuplicateIssueLinkException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This record already has a Linear issue link.');
    }
}
