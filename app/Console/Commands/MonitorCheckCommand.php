<?php

namespace App\Console\Commands;

use App\Application\Scheduling\CheckExecutor;
use App\Application\Scheduling\DueMonitorClaimer;
use Illuminate\Console\Command;

class MonitorCheckCommand extends Command
{
    protected $signature = 'monitor:check {--limit=50} {--lease=60}';

    protected $description = 'Claim due monitors and execute health checks sequentially';

    public function handle(
        DueMonitorClaimer $claimer,
        CheckExecutor $executor,
    ): int {
        $limit = (int) $this->option('limit');
        $lease = (int) $this->option('lease');

        $monitors = $claimer->claimDueMonitors($limit, $lease);

        if ($monitors->isEmpty()) {
            $this->info('No due monitors to check.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Claimed %d due monitors for check execution.', $monitors->count()));

        $executed = 0;
        foreach ($monitors as $monitor) {
            $result = $executor->execute($monitor);
            $this->line(sprintf('Monitor [%s] -> state: %s, latency: %dms', $monitor->name, $result->state->value, $result->latency_ms));
            $executed++;
        }

        $this->info(sprintf('Successfully executed %d checks.', $executed));

        return self::SUCCESS;
    }
}
