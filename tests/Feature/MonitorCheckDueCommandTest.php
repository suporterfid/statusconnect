<?php

namespace Tests\Feature;

use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MonitorCheckDueCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_executes_a_due_monitor_and_records_checker_heartbeat(): void
    {
        [$tenant, $environment] = $this->tenantContext();
        $monitor = Monitor::query()->create([
            'tenant_id' => $tenant->id,
            'environment_id' => $environment->id,
            'name' => 'Due target check',
            'kind' => 'http',
            'target' => 'http://target:8080/status/200',
            'next_check_at' => now()->subMinute(),
        ]);

        $this->assertSame(0, Artisan::call('monitor:check-due'));

        $this->assertNotNull($monitor->fresh()->last_checked_at);
        $this->assertSame(1, DB::table('check_results')->where('monitor_id', $monitor->id)->count());
        $this->assertSame(1, DB::table('system_heartbeats')->where('name', 'checker')->count());
    }

    /**
     * @return array{Tenant, Environment}
     */
    private function tenantContext(): array
    {
        $tenant = Tenant::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $environment = Environment::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Production',
            'slug' => 'production',
            'is_default' => true,
        ]);

        return [$tenant, $environment];
    }
}
