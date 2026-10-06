<?php

declare(strict_types=1);

namespace Dniccum\Linear\Enums;

enum LinearIssueSource: string
{
    case Automatic = 'automatic';
    case Manual = 'manual';
}
