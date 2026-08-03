<?php

namespace App\Application\Scheduling;

use App\Domain\Shared\Clock;
use App\Infrastructure\Persistence\Eloquent\Monitor;

/**
 * Mirrors the expired-claim recovery responsibility in taskconnect
 * app/Application/Scheduling/StaleClaimRecovery.php.
 */
final class StaleClaimRecovery
{
    public function __construct(private readonly Clock $clock)
    {
    }

    public function recover(?int $batchSize = null): int
    {
        $batchSize ??= max(1, (int) config('scheduler.stale_claim_recovery_batch_size', 50));
        $now = $this->clock->nowUtc()->format('Y-m-d H:i:s');
        $ids = Monitor::query()
            ->whereNotNull('claim_token')
            ->where('claim_expires_at', '<', $now)
            ->orderBy('claim_expires_at')
            ->limit($batchSize)
            ->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        return Monitor::query()
            ->whereIn('id', $ids)
            ->whereNotNull('claim_token')
            ->where('claim_expires_at', '<', $now)
            ->update([
                'claim_token' => null,
                'claimed_at' => null,
                'claim_expires_at' => null,
            ]);
    }
}
