<?php

namespace App\Console\Commands;

use App\Application\Scheduling\SequentialMonitorCheckRunner;
use Illuminate\Console\Command;

final class MonitorCheckDueCommand extends Command
{
    protected $signature = 'monitor:check-due';

    protected $description = 'Claim and execute due monitor checks within the tick budget';

    public function handle(SequentialMonitorCheckRunner $runner): int
    {
        $stats = $runner->run();

        $this->info(sprintf(
            'Claimed %d due monitor(s); executed %d%s.',
            $stats['claimed'],
            $stats['executed'],
            $stats['budget_stopped'] ? '; stopped on wall-clock budget' : '',
        ));

        return self::SUCCESS;
    }
}
