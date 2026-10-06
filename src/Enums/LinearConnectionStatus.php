<?php

declare(strict_types=1);

namespace Dniccum\Linear\Enums;

enum LinearConnectionStatus: string
{
    case Active = 'active';

    /**
     * Linear rejected the stored credentials (revoked, expired without a
     * usable refresh token, or missing a required scope). The owner must
     * authorize again before any further sync can happen.
     */
    case NeedsReconnect = 'needs_reconnect';
}
