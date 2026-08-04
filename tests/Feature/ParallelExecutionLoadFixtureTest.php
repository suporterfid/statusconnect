<?php

namespace Tests\Feature;

use App\Infrastructure\Persistence\Eloquent\CheckResult;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ParallelExecutionLoadFixtureTest extends TestCase
{
    use RefreshDatabase;

    public function test_thirty_five_second_monitors_complete_within_the_tick_budget(): void
    {
        config()->set('scheduler.check_concurrency', 10);
        config()->set('scheduler.check_wave_size', 10);
        $tenant = Tenant::query()->create(['name' => 'Load', 'slug' => 'load']);
        $environment = Environment::query()->create([
            'tenant_id' => $tenant->id, 'name' => 'Production', 'slug' => 'production', 'is_default' => true,
        ]);
        for ($index = 1; $index <= 30; $index++) {
            Monitor::query()->create([
                'tenant_id' => $tenant->id, 'environment_id' => $environment->id,
                'name' => "Delayed {$index}", 'kind' => 'http',
                'target' => 'http://target:8080/delay/5000',
                'timeout_ms' => 10_000, 'next_check_at' => now()->subMinute(),
            ]);
        }

        $startedAt = hrtime(true);
        $this->assertSame(0, Artisan::call('monitor:check-due'));
        $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;

        $this->assertSame(30, CheckResult::query()->count());
        $this->assertLessThan(45, $elapsedSeconds);
    }
}
