<?php

namespace App\Domain\Monitoring;

enum CheckState: string
{
    case UP = 'up';
    case DEGRADED = 'degraded';
    case DOWN = 'down';
    case BLOCKED = 'blocked';
    case SKIPPED = 'skipped';
}
