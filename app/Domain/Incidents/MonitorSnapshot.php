<?php

namespace App\Domain\Incidents;

use App\Domain\Monitoring\CheckState;
use DateTimeImmutable;

final readonly class MonitorSnapshot
{
    public function __construct(
        public CheckState $state,
        public int $consecutiveFailures,
        public int $consecutiveSuccesses,
        public ?DateTimeImmutable $firstFailureAt,
    ) {
    }
}
