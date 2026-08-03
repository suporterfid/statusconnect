<?php

namespace Tests\Feature;

use App\Infrastructure\Persistence\Eloquent\CheckResult;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ParallelMonitorCheckRunnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_executes_due_monitors_in_bounded_waves_and_persists_every_result(): void
    {
        config()->set('scheduler.check_concurrency', 2);
        config()->set('scheduler.check_wave_size', 2);
        $tenant = Tenant::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $environment = Environment::query()->create([
            'tenant_id' => $tenant->id, 'name' => 'Production', 'slug' => 'production', 'is_default' => true,
        ]);
        for ($index = 1; $index <= 4; $index++) {
            Monitor::query()->create([
                'tenant_id' => $tenant->id,
                'environment_id' => $environment->id,
                'name' => "Wave {$index}",
                'kind' => 'http',
                'target' => 'http://target:8080/status/200',
                'next_check_at' => now()->subMinute(),
            ]);
        }

        $this->assertSame(0, Artisan::call('monitor:check-due'));

        $this->assertSame(4, CheckResult::query()->count());
        $this->assertSame(0, Monitor::query()->whereNotNull('claim_token')->count());
    }
}
