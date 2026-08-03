<?php

namespace Tests\Feature;

use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MonitorMaintenanceCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_releases_only_expired_claims_and_records_maintenance_heartbeat(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $environment = Environment::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Production',
            'slug' => 'production',
            'is_default' => true,
        ]);

        $expired = $this->monitor($tenant, $environment, 'Expired', now()->subMinute());
        $active = $this->monitor($tenant, $environment, 'Active', now()->addMinute());

        $this->assertSame(0, Artisan::call('monitor:maintenance'));

        $this->assertNull($expired->fresh()->claim_token);
        $this->assertSame('active-claim', $active->fresh()->claim_token);
        $this->assertSame(1, DB::table('system_heartbeats')->where('name', 'maintenance')->count());
    }

    private function monitor(Tenant $tenant, Environment $environment, string $name, \DateTimeInterface $expiresAt): Monitor
    {
        return Monitor::query()->create([
            'tenant_id' => $tenant->id,
            'environment_id' => $environment->id,
            'name' => $name,
            'kind' => 'http',
            'target' => 'http://target:8080/status/200',
            'claim_token' => 'active-claim',
            'claimed_at' => now()->subMinute(),
            'claim_expires_at' => $expiresAt,
        ]);
    }
}
