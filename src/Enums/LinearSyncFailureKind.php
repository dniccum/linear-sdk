<?php

declare(strict_types=1);

namespace Dniccum\Linear\Enums;

/**
 * Which outbound Linear write a sync failure belongs to.
 */
enum LinearSyncFailureKind: string
{
    case Issue = 'issue';
    case Comment = 'comment';
}
