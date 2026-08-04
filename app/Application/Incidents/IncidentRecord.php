<?php

namespace App\Application\Incidents;

use App\Infrastructure\Persistence\Eloquent\CheckResult;

final readonly class IncidentRecord
{
    public function __construct(
        public CheckResult $checkResult,
        public bool $notificationAllowed,
    ) {
    }
}
