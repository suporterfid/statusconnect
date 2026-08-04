<?php

namespace App\Console\Commands;

use App\Application\Rollups\RollupService;
use App\Application\StatusPages\StatusPageCacheWriter;
use Illuminate\Console\Command;

final class MonitorRollupCommand extends Command
{
    protected $signature = 'monitor:rollup';
    protected $description = 'Aggregate closed monitor check-result buckets';

    public function handle(RollupService $rollups, StatusPageCacheWriter $statusPages): int
    {
        $buckets = $rollups->rollupClosedBuckets();
        $pages = $statusPages->refreshStalePages();
        $this->info(sprintf('Rolled up %d bucket(s); refreshed %d status page(s).', $buckets, $pages));
        return self::SUCCESS;
    }
}
