<?php

declare(strict_types=1);

namespace Dniccum\Linear\Enums;

/**
 * Delivery state of an outbound Linear write (an issue or a comment).
 */
enum LinearSyncStatus: string
{
    case Pending = 'pending';
    case Synced = 'synced';
    case Failed = 'failed';
}
