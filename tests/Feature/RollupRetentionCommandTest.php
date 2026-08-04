<?php

namespace Tests\Feature;

use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RollupRetentionCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_rollup_is_idempotent_and_retention_waits_for_coverage(): void
    {
        [$tenant, $environment, $monitor] = $this->monitor();
        $checkedAt = now()->subDays(8)->startOfHour()->addMinutes(5);

        $this->insertResult($tenant, $environment, $monitor, 'up', $checkedAt, 120);
        $this->insertResult($tenant, $environment, $monitor, 'down', $checkedAt->copy()->addMinute(), 240);

        config()->set('retention.check_results_days', 7);
        $this->assertSame(0, Artisan::call('monitor:maintenance'));
        $this->assertSame(2, DB::table('check_results')->count());

        $this->assertSame(0, Artisan::call('monitor:rollup'));
        $this->assertSame(2, DB::table('check_rollups')->count());
        $hour = DB::table('check_rollups')->where('bucket_kind', 'hour')->first();
        $this->assertSame(2, $hour->checks_total);
        $this->assertSame(1, $hour->up_count);
        $this->assertSame(1, $hour->down_count);
        $this->assertSame(60, $hour->downtime_seconds);

        $this->assertSame(0, Artisan::call('monitor:rollup'));
        $this->assertSame(2, DB::table('check_rollups')->count());

        $this->assertSame(0, Artisan::call('monitor:maintenance'));
        $this->assertSame(0, DB::table('check_results')->count());
    }

    private function monitor(): array
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
            'name' => 'API',
            'kind' => 'http',
            'target' => 'http://target:8080/status/200',
            'interval_seconds' => 60,
        ]);

        return [$tenant, $environment, $monitor];
    }

    private function insertResult(Tenant $tenant, Environment $environment, Monitor $monitor, string $state, \DateTimeInterface $checkedAt, int $latency): void
    {
        DB::table('check_results')->insert([
            'public_id' => 'res_' . bin2hex(random_bytes(12)),
            'tenant_id' => $tenant->id,
            'environment_id' => $environment->id,
            'monitor_id' => $monitor->id,
            'state' => $state,
            'latency_ms' => $latency,
            'checked_at' => $checkedAt,
            'created_at' => $checkedAt,
        ]);
    }
}
