<?php

namespace Tests\Feature;

use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Incident;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidentPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_unique_open_incident_constraint_prevents_a_second_open_monitor_incident(): void
    {
        $monitor = $this->monitor();

        Incident::query()->create($this->incidentAttributes($monitor));

        $this->expectException(QueryException::class);

        Incident::query()->create($this->incidentAttributes($monitor));
    }

    public function test_resolved_incidents_release_the_open_incident_constraint(): void
    {
        $monitor = $this->monitor();
        $first = Incident::query()->create($this->incidentAttributes($monitor));
        $first->update([
            'resolved_at' => '2026-08-04 10:10:00',
            'resolved_flag' => null,
            'duration_seconds' => 600,
        ]);

        Incident::query()->create($this->incidentAttributes($monitor, ['started_at' => '2026-08-04 10:11:00']));

        $this->assertSame(2, Incident::query()->count());
    }

    private function monitor(): Monitor
    {
        $tenant = Tenant::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $environment = Environment::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Production',
            'slug' => 'production',
            'is_default' => true,
        ]);

        return Monitor::query()->create([
            'tenant_id' => $tenant->id,
            'environment_id' => $environment->id,
            'name' => 'API',
            'kind' => 'http',
            'target' => 'https://example.test',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function incidentAttributes(Monitor $monitor, array $overrides = []): array
    {
        return array_replace([
            'tenant_id' => $monitor->tenant_id,
            'environment_id' => $monitor->environment_id,
            'monitor_id' => $monitor->id,
            'manual' => false,
            'resolved_flag' => false,
            'started_at' => '2026-08-04 10:00:00',
            'confirmed_at' => '2026-08-04 10:02:00',
            'severity' => 'major',
            'summary' => 'HTTP check failed',
        ], $overrides);
    }
}
