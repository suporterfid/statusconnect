<?php

namespace App\Console\Commands;

use App\Application\Scheduling\HeartbeatWriter;
use App\Application\Scheduling\StaleClaimRecovery;
use App\Application\Rollups\RollupService;
use Illuminate\Console\Command;

// Mirrors taskconnect app/Console/Commands/MaintenanceCommand.php.
final class MonitorMaintenanceCommand extends Command
{
    protected $signature = 'monitor:maintenance';

    protected $description = 'Recover stale monitor claims';

    public function handle(StaleClaimRecovery $recovery, HeartbeatWriter $heartbeatWriter, RollupService $rollups): int
    {
        $recovered = $recovery->recover();
        $deleted = $rollups->deleteCoveredRawResults();
        $heartbeatWriter->record('maintenance', ['stale_claims_recovered' => $recovered, 'raw_results_deleted' => $deleted]);

        $this->info(sprintf('Recovered %d stale monitor claim(s).', $recovered));

        return self::SUCCESS;
    }
}
