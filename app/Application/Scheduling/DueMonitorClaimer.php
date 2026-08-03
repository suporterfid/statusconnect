<?php

namespace App\Application\Scheduling;

use App\Domain\Shared\Clock;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DueMonitorClaimer
{
    public function __construct(
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return Collection<int, Monitor>
     */
    /**
     * @param  list<int>  $excludedMonitorIds
     */
    public function claimDueMonitors(
        int $limit = 50,
        ?int $leaseSeconds = null,
        array $excludedMonitorIds = [],
        ?int $maxTimeoutMs = null,
    ): Collection
    {
        $now = $this->clock->nowUtc();
        $leaseSeconds ??= max(1, (int) config('scheduler.claim_ttl_minutes', 5)) * 60;
        $claimExpiresAt = $now->modify("+{$leaseSeconds} seconds");

        try {
            return DB::transaction(function () use ($now, $claimExpiresAt, $limit, $excludedMonitorIds, $maxTimeoutMs) {
            $nowFormatted = $now->format('Y-m-d H:i:s');

            // Mirrors taskconnect app/Application/Scheduling/DueTaskClaimer.php.
            $query = Monitor::query()
                ->where('enabled', true)
                ->whereNotNull('next_check_at')
                ->where('next_check_at', '<=', $nowFormatted)
                ->where(function ($q) use ($nowFormatted) {
                    $q->whereNull('claim_token')
                        ->orWhere('claim_expires_at', '<', $nowFormatted);
                })
                ->orderBy('next_check_at')
                ->limit($limit);

            if ($excludedMonitorIds !== []) {
                $query->whereNotIn('id', $excludedMonitorIds);
            }

            if ($maxTimeoutMs !== null) {
                $query->where('timeout_ms', '<=', $maxTimeoutMs);
            }

            if (DB::connection()->getDriverName() === 'mysql') {
                $query->lock('FOR UPDATE SKIP LOCKED');
            } else {
                // SQLite has no SKIP LOCKED; its transaction lock is the portable test fallback.
                $query->lockForUpdate();
            }

            $candidates = $query->get();

            $claimed = new Collection();
            foreach ($candidates as $candidate) {
                $claimToken = (string) Str::uuid();
                $nextCheckAt = $this->nextCheckAt($candidate, $now);

                $updated = Monitor::query()
                    ->whereKey($candidate->id)
                    ->where('enabled', true)
                    ->whereNotNull('next_check_at')
                    ->where('next_check_at', '<=', $nowFormatted)
                    ->where(function ($q) use ($nowFormatted) {
                        $q->whereNull('claim_token')
                            ->orWhere('claim_expires_at', '<', $nowFormatted);
                    })
                    ->when($maxTimeoutMs !== null, function ($query) use ($maxTimeoutMs) {
                        $query->where('timeout_ms', '<=', $maxTimeoutMs);
                    })
                    ->update([
                        'claim_token' => $claimToken,
                        'claimed_at' => $now->format('Y-m-d H:i:s'),
                        'claim_expires_at' => $claimExpiresAt->format('Y-m-d H:i:s'),
                        'next_check_at' => $nextCheckAt->format('Y-m-d H:i:s'),
                    ]);

                if ($updated === 0) {
                    continue;
                }

                $candidate->refresh();
                $claimed->push($candidate->load(['assertions', 'tenant', 'environment']));
            }

                return $claimed;
            });
        } catch (QueryException $exception) {
            if (DB::connection()->getDriverName() === 'sqlite'
                && str_contains(strtolower($exception->getMessage()), 'database is locked')) {
                return new Collection();
            }

            throw $exception;
        }
    }

    private function nextCheckAt(Monitor $monitor, DateTimeImmutable $now): DateTimeImmutable
    {
        $previous = $monitor->next_check_at === null
            ? $now
            : DateTimeImmutable::createFromInterface($monitor->next_check_at);
        $nextFromPreviousPhase = $previous->modify(sprintf('+%d seconds', $monitor->interval_seconds));

        return $nextFromPreviousPhase > $now ? $nextFromPreviousPhase : $now;
    }

    public function releaseClaim(Monitor $monitor): bool
    {
        if ($monitor->claim_token === null) {
            return false;
        }

        return Monitor::query()
            ->whereKey($monitor->id)
            ->where('claim_token', $monitor->claim_token)
            ->update([
                'claim_token' => null,
                'claimed_at' => null,
                'claim_expires_at' => null,
            ]) === 1;
    }
}
