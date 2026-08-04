<?php

namespace App\Application\StatusPages;

use App\Domain\Shared\Clock;
use Illuminate\Support\Facades\DB;

final class StatusPageCacheWriter
{
    public function __construct(private readonly Clock $clock)
    {
    }

    /**
     * Rebuild only pages that have no snapshot or whose selected monitor/component changed.
     */
    public function refreshStalePages(): int
    {
        $refreshed = 0;
        foreach (DB::table('status_pages')->orderBy('id')->get() as $page) {
            $cache = DB::table('status_page_cache')->where('status_page_id', $page->id)->first();
            if (! $this->needsRefresh($page, $cache)) {
                continue;
            }

            $payload = $this->payloadFor($page);
            $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);
            $now = $this->clock->nowUtc()->format('Y-m-d H:i:s');

            DB::table('status_page_cache')->updateOrInsert(
                ['status_page_id' => $page->id],
                [
                    'payload_json' => $payloadJson,
                    'etag' => hash('sha256', $payloadJson),
                    'generated_at' => $now,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
            $refreshed++;
        }

        return $refreshed;
    }

    private function needsRefresh(object $page, ?object $cache): bool
    {
        if ($cache === null || $page->updated_at > $cache->generated_at) {
            return true;
        }

        return DB::table('status_page_components as component')
            ->leftJoin('status_page_component_monitors as component_monitor', 'component_monitor.status_page_component_id', '=', 'component.id')
            ->leftJoin('monitors as monitor', function ($join) use ($page): void {
                $join->on('monitor.id', '=', 'component_monitor.monitor_id')
                    ->where('monitor.environment_id', $page->environment_id);
            })
            ->where('component.status_page_id', $page->id)
            ->where(function ($query) use ($cache): void {
                $query->where('component.updated_at', '>', $cache->generated_at)
                    ->orWhere('component_monitor.updated_at', '>', $cache->generated_at)
                    ->orWhere('monitor.updated_at', '>', $cache->generated_at);
            })
            ->exists();
    }

    private function payloadFor(object $page): array
    {
        $now = $this->clock->nowUtc();
        $components = DB::table('status_page_components as component')
            ->leftJoin('status_page_component_monitors as component_monitor', 'component_monitor.status_page_component_id', '=', 'component.id')
            ->leftJoin('monitors as monitor', function ($join) use ($page): void {
                $join->on('monitor.id', '=', 'component_monitor.monitor_id')
                    ->where('monitor.public_safe', true)
                    ->where('monitor.environment_id', $page->environment_id);
            })
            ->where('component.status_page_id', $page->id)
            ->orderBy('component.sort_order')
            ->orderBy('component.id')
            ->get([
                'component.id as component_id',
                'component.name as component_name',
                'monitor.id as monitor_id',
                'monitor.current_state',
            ]);

        $monitorIds = $components->pluck('monitor_id')->filter()->map(static fn ($id): int => (int) $id)->all();
        $rollups = $monitorIds === [] ? collect() : DB::table('check_rollups')
            ->where('bucket_kind', 'day')
            ->whereIn('monitor_id', $monitorIds)
            ->where('bucket_start', '>=', $now->modify('-90 days')->format('Y-m-d H:i:s'))
            ->get();

        // The current day has no closed daily rollup yet. Aggregate its partial bucket here;
        // the public request still reads only the resulting status_page_cache snapshot.
        $partial = $monitorIds === [] ? collect() : DB::table('check_results')
            ->whereIn('monitor_id', $monitorIds)
            ->where('checked_at', '>=', $now->setTime(0, 0)->format('Y-m-d H:i:s'))
            ->selectRaw('monitor_id, state, count(*) as checks_total')
            ->groupBy('monitor_id', 'state')
            ->get()
            ->groupBy('monitor_id')
            ->map(function ($results) use ($now): object {
                $counts = $results->keyBy('state');

                return (object) [
                    'monitor_id' => $results->first()->monitor_id,
                    'bucket_start' => $now->setTime(0, 0)->format('Y-m-d H:i:s'),
                    'up_count' => (int) ($counts['up']->checks_total ?? 0),
                    'degraded_count' => (int) ($counts['degraded']->checks_total ?? 0),
                    'down_count' => (int) ($counts['down']->checks_total ?? 0),
                    'blocked_count' => (int) ($counts['blocked']->checks_total ?? 0),
                    'skipped_count' => (int) ($counts['skipped']->checks_total ?? 0),
                    'no_data_count' => (int) ($counts['no_data']->checks_total ?? 0),
                ];
            });

        $byMonitor = $rollups->concat($partial)->groupBy('monitor_id');
        $byComponent = $components->groupBy('component_id');
        $serializedComponents = $byComponent->map(function ($rows) use ($byMonitor): array {
            $monitors = $rows->filter(static fn (object $row): bool => $row->monitor_id !== null);

            return [
                'name' => $rows->first()->component_name,
                'state' => $this->publicState($monitors->pluck('current_state')->all()),
                'uptime' => $this->uptime($monitors, $byMonitor),
                'history' => $this->history($monitors, $byMonitor),
            ];
        })->values()->all();

        return [
            'title' => $page->title,
            'locale' => in_array($page->locale, ['en', 'pt_BR'], true) ? $page->locale : 'en',
            'timezone' => $page->timezone,
            'overall_state' => $this->publicState(array_column($serializedComponents, 'state')),
            'components' => $serializedComponents,
            'maintenance' => [],
            'incidents' => $this->incidentsFor($monitorIds),
            'generated_at' => $this->clock->nowUtc()->format(DATE_ATOM),
        ];
    }

    private function uptime($monitors, $byMonitor): array
    {
        $periods = ['24h' => 1, '7d' => 7, '30d' => 30, '90d' => 90];
        $result = [];
        foreach ($periods as $label => $days) {
            $cutoff = $this->clock->nowUtc()->modify(sprintf('-%d days', $days))->format('Y-m-d H:i:s');
            $counts = ['up' => 0, 'degraded' => 0, 'down' => 0];
            foreach ($monitors as $monitor) {
                foreach (($byMonitor[$monitor->monitor_id] ?? collect())->filter(static fn (object $rollup): bool => $rollup->bucket_start >= $cutoff) as $rollup) {
                    foreach (array_keys($counts) as $state) {
                        $counts[$state] += (int) $rollup->{$state . '_count'};
                    }
                }
            }
            $denominator = array_sum($counts);
            $result[$label] = $denominator === 0 ? 0.0 : round((($counts['up'] + $counts['degraded']) / $denominator) * 100, 2);
        }

        return $result;
    }

    private function history($monitors, $byMonitor): array
    {
        $history = [];
        for ($offset = 89; $offset >= 0; $offset--) {
            $date = $this->clock->nowUtc()->modify(sprintf('-%d days', $offset))->format('Y-m-d');
            $states = [];
            foreach ($monitors as $monitor) {
                $rollup = ($byMonitor[$monitor->monitor_id] ?? collect())->first(
                    static fn (object $row): bool => substr($row->bucket_start, 0, 10) === $date,
                );
                if ($rollup !== null) {
                    $states[] = (int) $rollup->down_count > 0 ? 'down' : ((int) $rollup->degraded_count > 0 ? 'degraded' : 'up');
                }
            }
            $history[] = ['date' => $date, 'state' => $this->publicState($states)];
        }

        return $history;
    }

    private function incidentsFor(array $monitorIds): array
    {
        if ($monitorIds === []) {
            return [];
        }

        return DB::table('incidents')
            ->whereIn('monitor_id', $monitorIds)
            ->orderByDesc('started_at')
            ->limit(100)
            ->get(['id', 'started_at', 'resolved_at'])
            ->map(function (object $incident): array {
                return [
                    'state' => 'major_outage',
                    'started_at' => $incident->started_at,
                    'resolved_at' => $incident->resolved_at,
                    // Operator-entered update messages can contain target details; publish only fixed status and time.
                    'updates' => DB::table('incident_updates')
                        ->where('incident_id', $incident->id)
                        ->orderBy('published_at')
                        ->get(['status', 'published_at'])
                        ->map(static fn (object $update): array => [
                            'status' => $update->status,
                            'published_at' => $update->published_at,
                        ])
                        ->all(),
                ];
            })
            ->all();
    }

    private function publicState(array $states): string
    {
        if (in_array('down', $states, true) || in_array('major_outage', $states, true)) {
            return 'major_outage';
        }
        if (in_array('degraded', $states, true)) {
            return 'degraded';
        }
        if (in_array('up', $states, true) || in_array('operational', $states, true)) {
            return 'operational';
        }

        return 'no_data';
    }
}
