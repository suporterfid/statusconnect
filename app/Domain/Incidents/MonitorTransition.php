<?php

namespace App\Domain\Incidents;

use App\Domain\Monitoring\CheckState;
use DateTimeImmutable;

final readonly class MonitorTransition
{
    public function __construct(
        public CheckState $nextState,
        public int $consecutiveFailures,
        public int $consecutiveSuccesses,
        public ?DateTimeImmutable $firstFailureAt,
        public IncidentAction $action,
    ) {
    }
}
