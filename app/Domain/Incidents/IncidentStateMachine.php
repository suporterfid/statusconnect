<?php

namespace App\Domain\Incidents;

use App\Domain\Monitoring\CheckState;
use DateTimeImmutable;

final class IncidentStateMachine
{
    public function transition(
        MonitorSnapshot $monitor,
        CheckState $outcome,
        DateTimeImmutable $checkedAt,
        int $confirmationThreshold,
        int $recoveryThreshold,
    ): MonitorTransition {
        if ($outcome === CheckState::BLOCKED) {
            return new MonitorTransition(
                $monitor->state,
                $monitor->consecutiveFailures,
                $monitor->consecutiveSuccesses,
                $monitor->firstFailureAt,
                IncidentAction::CONFIGURATION_FAULT,
            );
        }

        if ($outcome === CheckState::UP) {
            return $this->onSuccess($monitor, max(1, $recoveryThreshold));
        }

        if (! in_array($outcome, [CheckState::DOWN, CheckState::DEGRADED], true)) {
            return new MonitorTransition(
                $monitor->state,
                $monitor->consecutiveFailures,
                $monitor->consecutiveSuccesses,
                $monitor->firstFailureAt,
                IncidentAction::NONE,
            );
        }

        $failures = $monitor->consecutiveFailures + 1;
        $firstFailureAt = $monitor->firstFailureAt ?? $checkedAt;

        if ($failures < max(1, $confirmationThreshold)) {
            return new MonitorTransition($monitor->state, $failures, 0, $firstFailureAt, IncidentAction::NONE);
        }

        $alreadyConfirmed = in_array($monitor->state, [CheckState::DOWN, CheckState::DEGRADED], true);

        return new MonitorTransition(
            $outcome,
            $failures,
            0,
            $firstFailureAt,
            $alreadyConfirmed ? IncidentAction::NONE : IncidentAction::OPEN,
        );
    }

    private function onSuccess(MonitorSnapshot $monitor, int $recoveryThreshold): MonitorTransition
    {
        $successes = $monitor->consecutiveSuccesses + 1;
        $isConfirmedOutage = in_array($monitor->state, [CheckState::DOWN, CheckState::DEGRADED], true);

        if ($isConfirmedOutage && $successes >= $recoveryThreshold) {
            return new MonitorTransition(CheckState::UP, 0, $successes, null, IncidentAction::RESOLVE);
        }

        if ($isConfirmedOutage) {
            return new MonitorTransition($monitor->state, 0, $successes, $monitor->firstFailureAt, IncidentAction::NONE);
        }

        return new MonitorTransition(CheckState::UP, 0, $successes, null, IncidentAction::NONE);
    }
}
