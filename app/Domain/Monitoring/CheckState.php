<?php

namespace App\Domain\Monitoring;

enum CheckState: string
{
    case PENDING = 'pending';
    case UP = 'up';
    case DEGRADED = 'degraded';
    case DOWN = 'down';
    case BLOCKED = 'blocked';
    case SKIPPED = 'skipped';
    case PAUSED = 'paused';
    case MAINTENANCE = 'maintenance';
}
