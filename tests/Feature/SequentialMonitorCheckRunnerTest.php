<?php

namespace Tests\Feature;

use App\Application\Scheduling\SequentialMonitorCheckRunner;
use App\Application\Scheduling\TickBudget;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SequentialMonitorCheckRunnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_exhausted_budget_stops_before_claiming_new_work(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $environment = Environment::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Production',
            'slug' => 'production',
            'is_default' => true,
        ]);
        $monitor = Monitor::query()->create([
            'tenant_id' => $tenant->id,
            'environment_id' => $environment->id,
            'name' => 'Must remain unclaimed',
            'kind' => 'http',
            'target' => 'http://target:8080/status/200',
            'next_check_at' => now()->subMinute(),
        ]);
        $now = 1_000.0;
        $budget = new TickBudget(1_000.0, 0.0, static function () use (&$now): float {
            return $now;
        });

        $stats = app(SequentialMonitorCheckRunner::class)->run($budget);

        $this->assertSame(0, $stats['claimed']);
        $this->assertTrue($stats['budget_stopped']);
        $this->assertNull($monitor->fresh()->claim_token);
        $this->assertSame(0, DB::table('check_results')->where('monitor_id', $monitor->id)->count());
        $this->assertSame(1, DB::table('system_heartbeats')->where('name', 'checker')->count());
    }

    public function test_does_not_claim_a_monitor_when_its_timeout_exceeds_remaining_budget(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $environment = Environment::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Production',
            'slug' => 'production',
            'is_default' => true,
        ]);
        $monitor = Monitor::query()->create([
            'tenant_id' => $tenant->id,
            'environment_id' => $environment->id,
            'name' => 'Too slow for remaining budget',
            'kind' => 'http',
            'target' => 'http://target:8080/status/200',
            'timeout_ms' => 1_499,
            'next_check_at' => now()->subMinute(),
        ]);
        $now = 1_000.0;
        $budget = new TickBudget(1_000.0, 1.5, static function () use (&$now): float {
            return $now;
        });

        $stats = app(SequentialMonitorCheckRunner::class)->run($budget);

        $this->assertSame(0, $stats['claimed']);
        $this->assertTrue($stats['budget_stopped']);
        $this->assertNull($monitor->fresh()->claim_token);
        $this->assertSame(0, DB::table('check_results')->where('monitor_id', $monitor->id)->count());
    }
}
