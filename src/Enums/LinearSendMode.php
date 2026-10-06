<?php

declare(strict_types=1);

namespace Dniccum\Linear\Enums;

/**
 * How an owner's records reach Linear: filed as soon as the model event fires,
 * or only when `sendToLinear()` is called.
 */
enum LinearSendMode: string
{
    case Automatic = 'automatic';
    case Manual = 'manual';
}
