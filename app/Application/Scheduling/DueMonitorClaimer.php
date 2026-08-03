<?php

namespace App\Application\Scheduling;

use App\Domain\Shared\Clock;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DueMonitorClaimer
{
    public function __construct(
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return Collection<int, Monitor>
     */
    public function claimDueMonitors(int $limit = 50, int $leaseSeconds = 60): Collection
    {
        $now = $this->clock->nowUtc();
        $claimToken = Str::random(32);
        $claimExpiresAt = $now->modify("+{$leaseSeconds} seconds");

        return DB::transaction(function () use ($now, $claimToken, $claimExpiresAt, $limit) {
            $nowFormatted = $now->format('Y-m-d H:i:s');

            $dueIds = Monitor::query()
                ->where('enabled', true)
                ->where(function ($q) use ($nowFormatted) {
                    $q->whereNull('next_check_at')
                        ->orWhere('next_check_at', '<=', $nowFormatted);
                })
                ->where(function ($q) use ($nowFormatted) {
                    $q->whereNull('claim_expires_at')
                        ->orWhere('claim_expires_at', '<=', $nowFormatted);
                })
                ->orderByRaw('CASE WHEN next_check_at IS NULL THEN 0 ELSE 1 END, next_check_at ASC')
                ->limit($limit)
                ->pluck('id')
                ->toArray();

            if ($dueIds === []) {
                return new Collection();
            }

            Monitor::query()
                ->whereIn('id', $dueIds)
                ->update([
                    'claim_token' => $claimToken,
                    'claimed_at' => $now,
                    'claim_expires_at' => $claimExpiresAt,
                ]);

            return Monitor::query()
                ->whereIn('id', $dueIds)
                ->where('claim_token', $claimToken)
                ->with(['assertions', 'tenant', 'environment'])
                ->get();
        });
    }
}
