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

        while ($budget->canClaimMore()) {
            $remainingTimeoutMs = (int) floor($budget->remainingSeconds() * 1000);
            if ($remainingTimeoutMs <= 0) {
                break;
            }

            $monitors = $this->claimer->claimDueMonitors(
                limit: 1,
                excludedMonitorIds: $claimedMonitorIds,
                maxTimeoutMs: $remainingTimeoutMs,
            );
            $monitor = $monitors->first();

            if ($monitor === null) {
                break;
            }

            $claimed++;
            $claimedMonitorIds[] = $monitor->id;
            if ($this->executor->execute($monitor) !== null) {
                $executed++;
            }
        }

        $budgetStopped = $budget->exhausted();
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
