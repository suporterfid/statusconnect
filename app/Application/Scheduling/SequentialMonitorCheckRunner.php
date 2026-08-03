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
            $monitors = $this->claimer->claimDueMonitors(
                limit: 1,
                excludedMonitorIds: $claimedMonitorIds,
            );
            $monitor = $monitors->first();

            if ($monitor === null) {
                break;
            }

            $claimed++;
            $claimedMonitorIds[] = $monitor->id;
            $this->executor->execute($monitor);
            $executed++;
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
