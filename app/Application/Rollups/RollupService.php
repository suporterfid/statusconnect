<?php

namespace App\Application\Rollups;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class RollupService
{
    public function rollupClosedBuckets(): int
    {
        $now = now();
        $groups = [];
        foreach (DB::table('check_results')->orderBy('id')->cursor() as $result) {
            $checkedAt = now()->parse($result->checked_at);
            foreach (['hour' => $checkedAt->copy()->startOfHour(), 'day' => $checkedAt->copy()->startOfDay()] as $kind => $start) {
                if (($kind === 'hour' && $start->copy()->addHour()->gt($now)) || ($kind === 'day' && $start->copy()->addDay()->gt($now))) {
                    continue;
                }
                $key = $result->monitor_id . '|' . $kind . '|' . $start->toDateTimeString();
                $groups[$key] ??= ['monitor_id' => $result->monitor_id, 'bucket_kind' => $kind, 'bucket_start' => $start, 'results' => []];
                $groups[$key]['results'][] = $result;
            }
        }

        foreach ($groups as $group) {
            $states = array_count_values(array_map(static fn ($row) => $row->state, $group['results']));
            $latencies = array_values(array_filter(array_map(static fn ($row) => (int) $row->latency_ms, $group['results']), static fn ($value) => $value > 0));
            sort($latencies);
            $count = count($latencies);
            $interval = (int) DB::table('monitors')->where('id', $group['monitor_id'])->value('interval_seconds');
            $values = [
                'up_count' => $states['up'] ?? 0,
                'degraded_count' => $states['degraded'] ?? 0,
                'down_count' => $states['down'] ?? 0,
                'blocked_count' => $states['blocked'] ?? 0,
                'skipped_count' => $states['skipped'] ?? 0,
                'no_data_count' => $states['no_data'] ?? 0,
                'downtime_seconds' => ($states['down'] ?? 0) * $interval,
                'checks_total' => count($group['results']),
                'latency_min_ms' => $count ? $latencies[0] : null,
                'latency_avg_ms' => $count ? (int) round(array_sum($latencies) / $count) : null,
                'latency_p50_ms' => $count ? $latencies[(int) floor(($count - 1) * .50)] : null,
                'latency_p95_ms' => $count ? $latencies[(int) floor(($count - 1) * .95)] : null,
                'latency_max_ms' => $count ? $latencies[$count - 1] : null,
                'updated_at' => $now,
            ];
            DB::table('check_rollups')->updateOrInsert([
                'monitor_id' => $group['monitor_id'], 'bucket_start' => $group['bucket_start'], 'bucket_kind' => $group['bucket_kind'],
            ], $values + ['created_at' => $now]);
        }

        return count($groups);
    }

    public function deleteCoveredRawResults(): int
    {
        $cutoff = now()->subDays((int) config('retention.check_results_days', 7));
        $limit = (int) config('retention.delete_chunk_size', 500);
        $ids = [];
        foreach (DB::table('check_results')->where('checked_at', '<', $cutoff)->orderBy('id')->limit($limit)->get() as $result) {
            $bucket = now()->parse($result->checked_at)->startOfDay();
            if (DB::table('check_rollups')->where(['monitor_id' => $result->monitor_id, 'bucket_start' => $bucket, 'bucket_kind' => 'day'])->exists()) {
                $ids[] = $result->id;
            }
        }

        return $ids === [] ? 0 : DB::table('check_results')->whereIn('id', $ids)->delete();
    }
}
