<?php

namespace App\Application\Scheduling;

final class SequentialMonitorCheckRunner
{
    public function __construct(
        private readonly DueMonitorClaimer $claimer,
        private readonly CheckExecutor $executor,
        private readonly HeartbeatWriter $heartbeatWriter,
    ) {
    }

    /**
     * @return array{claimed: int, executed: int, budget_stopped: bool}
     */
    public function run(?TickBudget $budget = null): array
    {
        $budget ??= TickBudget::fromConfig();
        $claimed = 0;
        $executed = 0;
        $claimedMonitorIds = [];
        $executionReserveSeconds = max(0, (int) config('scheduler.execution_reserve_seconds', 1));
        $budgetStopped = false;

        while ($budget->canClaimMore()) {
            $maxTimeoutSeconds = (int) floor($budget->remainingSeconds() - $executionReserveSeconds);
            if ($maxTimeoutSeconds <= 0) {
                $budgetStopped = true;
                break;
            }

            $monitors = $this->claimer->claimDueMonitors(
                limit: 1,
                excludedMonitorIds: $claimedMonitorIds,
                maxTimeoutMs: $maxTimeoutSeconds * 1000,
            );
            $monitor = $monitors->first();

            if ($monitor === null) {
                break;
            }

            $claimed++;
            $claimedMonitorIds[] = $monitor->id;
            $timeoutSeconds = (int) ceil($monitor->timeout_ms / 1000);
            if ($budget->remainingSeconds() <= $timeoutSeconds + $executionReserveSeconds) {
                $this->claimer->releaseClaim($monitor);
                $budgetStopped = true;

                break;
            }

            if ($this->executor->execute($monitor) !== null) {
                $executed++;
            }
        }

        $budgetStopped = $budgetStopped || $budget->exhausted();
        $this->heartbeatWriter->record('checker', [
            'claimed' => $claimed,
            'executed' => $executed,
            'budget_stopped' => $budgetStopped,
        ]);

        return [
            'claimed' => $claimed,
            'executed' => $executed,
            'budget_stopped' => $budgetStopped,
        ];
    }
}
