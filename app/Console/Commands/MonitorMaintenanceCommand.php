<?php

namespace App\Console\Commands;

use App\Application\Scheduling\HeartbeatWriter;
use App\Application\Scheduling\StaleClaimRecovery;
use Illuminate\Console\Command;

// Mirrors taskconnect app/Console/Commands/MaintenanceCommand.php.
final class MonitorMaintenanceCommand extends Command
{
    protected $signature = 'monitor:maintenance';

    protected $description = 'Recover stale monitor claims';

    public function handle(StaleClaimRecovery $recovery, HeartbeatWriter $heartbeatWriter): int
    {
        $recovered = $recovery->recover();
        $heartbeatWriter->record('maintenance', ['stale_claims_recovered' => $recovered]);

        $this->info(sprintf('Recovered %d stale monitor claim(s).', $recovered));

        return self::SUCCESS;
    }
}
