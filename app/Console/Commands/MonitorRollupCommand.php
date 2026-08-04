<?php

namespace App\Console\Commands;

use App\Application\Rollups\RollupService;
use Illuminate\Console\Command;

final class MonitorRollupCommand extends Command
{
    protected $signature = 'monitor:rollup';
    protected $description = 'Aggregate closed monitor check-result buckets';

    public function handle(RollupService $rollups): int
    {
        $this->info(sprintf('Rolled up %d bucket(s).', $rollups->rollupClosedBuckets()));
        return self::SUCCESS;
    }
}
