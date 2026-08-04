<?php

namespace Tests\Feature;

use App\Application\Incidents\IncidentService;
use App\Domain\Monitoring\CheckOutcome;
use App\Domain\Monitoring\CheckState;
use App\Domain\Monitoring\EvaluationResult;
use App\Domain\Shared\FrozenClock;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Incident;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidentServiceTest extends TestCase
{
    use RefreshDatabase;

    private FrozenClock $clock;

    private IncidentService $service;

    private Monitor $monitor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock(new DateTimeImmutable('2026-08-04T10:00:00Z'));
        $this->service = new IncidentService(
            app(\App\Domain\Incidents\IncidentStateMachine::class),
        );

        $tenant = Tenant::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $environment = Environment::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Production',
            'slug' => 'production',
            'is_default' => true,
        ]);
        $this->monitor = Monitor::query()->create([
            'tenant_id' => $tenant->id,
            'environment_id' => $environment->id,
            'name' => 'API',
            'kind' => 'http',
            'target' => 'https://example.test',
            'confirmation_threshold' => 3,
            'recovery_threshold' => 2,
        ]);
    }

    public function test_opened_incident_starts_at_the_first_failing_check(): void
    {
        $this->record(CheckState::DOWN, '10:00');
        $this->record(CheckState::DOWN, '10:01');
        $this->record(CheckState::DOWN, '10:02');

        $incident = Incident::query()->sole();

        $this->assertSame('10:00', $incident->started_at->format('H:i'));
        $this->assertSame('10:02', $incident->confirmed_at->format('H:i'));
        $this->assertSame('major', $incident->severity);
        $this->assertSame(CheckState::DOWN, $this->monitor->fresh()->current_state);
    }

    public function test_blocked_check_preserves_failure_streak_and_opens_no_incident(): void
    {
        $this->monitor->update([
            'consecutive_failures' => 1,
            'first_failure_at' => '2026-08-04 10:00:00',
        ]);

        $this->record(CheckState::BLOCKED, '10:01');

        $fresh = $this->monitor->fresh();
        $this->assertSame(1, $fresh->consecutive_failures);
        $this->assertSame(CheckState::UP, $fresh->current_state);
        $this->assertSame(0, Incident::query()->count());
    }

    public function test_recovery_resolves_only_after_the_configured_threshold(): void
    {
        $this->monitor->update([
            'current_state' => CheckState::DOWN,
            'consecutive_failures' => 3,
            'first_failure_at' => '2026-08-04 10:00:00',
        ]);
        $incident = Incident::query()->create([
            'tenant_id' => $this->monitor->tenant_id,
            'environment_id' => $this->monitor->environment_id,
            'monitor_id' => $this->monitor->id,
            'manual' => false,
            'resolved_flag' => false,
            'started_at' => '2026-08-04 10:00:00',
            'confirmed_at' => '2026-08-04 10:02:00',
            'severity' => 'major',
            'summary' => 'API is unavailable',
        ]);

        $this->record(CheckState::UP, '10:03');
        $this->assertNull($incident->fresh()->resolved_at);

        $this->record(CheckState::UP, '10:04');
        $resolved = $incident->fresh();

        $this->assertSame('10:04', $resolved->resolved_at->format('H:i'));
        $this->assertSame(240, $resolved->duration_seconds);
        $this->assertNull($resolved->resolved_flag);
    }

    private function record(CheckState $state, string $time): void
    {
        $this->clock->set(new DateTimeImmutable("2026-08-04T{$time}:00Z"));
        $this->service->record(
            $this->monitor->fresh(),
            new CheckOutcome(statusCode: $state === CheckState::UP ? 200 : 500, latencyMs: 12),
            new EvaluationResult($state, $state === CheckState::UP ? null : 'Check failed'),
            $this->clock->nowUtc(),
        );
    }
}
