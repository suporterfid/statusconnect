<?php

namespace Tests\Unit;

use App\Application\Scheduling\DueMonitorClaimer;
use App\Domain\Shared\FrozenClock;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Persistence\Eloquent\User;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DueMonitorClaimerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Environment $environment;

    private FrozenClock $clock;

    private DueMonitorClaimer $claimer;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->tenant = Tenant::query()->create([
            'name' => 'Acme Corp',
            'slug' => 'acme-corp',
        ]);
        $this->environment = Environment::query()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'production',
            'slug' => 'production',
            'is_default' => true,
        ]);

        $this->clock = new FrozenClock(new DateTimeImmutable('2026-08-03 12:00:00 UTC'));
        $this->claimer = new DueMonitorClaimer($this->clock);
    }

    public function test_claims_due_monitors_atomically(): void
    {
        // 1. Due monitor (next_check_at <= now)
        $dueMonitor = Monitor::query()->create([
            'tenant_id' => $this->tenant->id,
            'environment_id' => $this->environment->id,
            'name' => 'Due Monitor',
            'kind' => 'http',
            'target' => 'http://target:8095/status/200',
            'enabled' => true,
            'next_check_at' => new DateTimeImmutable('2026-08-03 11:59:00 UTC'),
        ]);

        // 2. Future monitor (next_check_at > now)
        Monitor::query()->create([
            'tenant_id' => $this->tenant->id,
            'environment_id' => $this->environment->id,
            'name' => 'Future Monitor',
            'kind' => 'http',
            'target' => 'http://target:8095/status/200',
            'enabled' => true,
            'next_check_at' => new DateTimeImmutable('2026-08-03 12:05:00 UTC'),
        ]);

        $claimed = $this->claimer->claimDueMonitors(limit: 50);

        $this->assertCount(1, $claimed);
        $this->assertEquals($dueMonitor->id, $claimed->first()->id);
        $this->assertNotNull($claimed->first()->claim_token);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $claimed->first()->claim_token,
        );
        $this->assertEquals('2026-08-03 12:05:00', $claimed->first()->claim_expires_at->format('Y-m-d H:i:s'));
    }

    public function test_claim_advances_next_check_at_from_the_previous_phase(): void
    {
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-08-03 12:00:30 UTC'));
        $this->claimer = new DueMonitorClaimer($this->clock);

        $monitor = Monitor::query()->create([
            'tenant_id' => $this->tenant->id,
            'environment_id' => $this->environment->id,
            'name' => 'Phase Preserving Monitor',
            'kind' => 'http',
            'target' => 'http://target:8095/status/200',
            'enabled' => true,
            'interval_seconds' => 60,
            'next_check_at' => new DateTimeImmutable('2026-08-03 12:00:00 UTC'),
        ]);

        $this->claimer->claimDueMonitors();

        $this->assertSame('2026-08-03 12:01:00', $monitor->fresh()->next_check_at->format('Y-m-d H:i:s'));
    }

    public function test_claims_a_monitor_scheduled_at_the_exact_current_second(): void
    {
        $monitor = Monitor::query()->create([
            'tenant_id' => $this->tenant->id,
            'environment_id' => $this->environment->id,
            'name' => 'Exactly Due Monitor',
            'kind' => 'http',
            'target' => 'http://target:8095/status/200',
            'enabled' => true,
            'next_check_at' => new DateTimeImmutable('2026-08-03 12:00:00 UTC'),
        ]);

        $claimed = $this->claimer->claimDueMonitors();

        $this->assertSame($monitor->id, $claimed->sole()->id);
    }

    public function test_late_claim_does_not_backfill_missed_intervals(): void
    {
        $monitor = Monitor::query()->create([
            'tenant_id' => $this->tenant->id,
            'environment_id' => $this->environment->id,
            'name' => 'Late Monitor',
            'kind' => 'http',
            'target' => 'http://target:8095/status/200',
            'enabled' => true,
            'interval_seconds' => 60,
            'next_check_at' => new DateTimeImmutable('2026-08-03 11:00:00 UTC'),
        ]);

        $this->claimer->claimDueMonitors();

        $this->assertSame('2026-08-03 12:00:00', $monitor->fresh()->next_check_at->format('Y-m-d H:i:s'));
    }

    public function test_does_not_claim_a_monitor_without_a_scheduled_next_check_at(): void
    {
        Monitor::query()->create([
            'tenant_id' => $this->tenant->id,
            'environment_id' => $this->environment->id,
            'name' => 'Awaiting Schedule',
            'kind' => 'http',
            'target' => 'http://target:8095/status/200',
            'enabled' => true,
            'next_check_at' => null,
        ]);

        $this->assertCount(0, $this->claimer->claimDueMonitors());
    }

    public function test_skips_active_claimed_monitors_within_lease(): void
    {
        Monitor::query()->create([
            'tenant_id' => $this->tenant->id,
            'environment_id' => $this->environment->id,
            'name' => 'Claimed Monitor',
            'kind' => 'http',
            'target' => 'http://target:8095/status/200',
            'enabled' => true,
            'next_check_at' => new DateTimeImmutable('2026-08-03 11:59:00 UTC'),
            'claim_token' => 'active_lease_token',
            'claim_expires_at' => new DateTimeImmutable('2026-08-03 12:05:00 UTC'), // expires in future
        ]);

        $claimed = $this->claimer->claimDueMonitors();

        $this->assertCount(0, $claimed);
    }

    public function test_does_not_reclaim_a_lease_expiring_at_the_exact_current_second(): void
    {
        Monitor::query()->create([
            'tenant_id' => $this->tenant->id,
            'environment_id' => $this->environment->id,
            'name' => 'Exactly Expiring Lease',
            'kind' => 'http',
            'target' => 'http://target:8095/status/200',
            'enabled' => true,
            'next_check_at' => new DateTimeImmutable('2026-08-03 11:59:00 UTC'),
            'claim_token' => 'still-owned-at-boundary',
            'claim_expires_at' => new DateTimeImmutable('2026-08-03 12:00:00 UTC'),
        ]);

        $this->assertCount(0, $this->claimer->claimDueMonitors());
    }

    public function test_overlapping_claim_attempts_do_not_double_claim_a_monitor(): void
    {
        Monitor::query()->create([
            'tenant_id' => $this->tenant->id,
            'environment_id' => $this->environment->id,
            'name' => 'Contended Monitor',
            'kind' => 'http',
            'target' => 'http://target:8095/status/200',
            'enabled' => true,
            'next_check_at' => new DateTimeImmutable('2026-08-03 11:59:00 UTC'),
        ]);

        $firstClaim = $this->claimer->claimDueMonitors(limit: 1);
        $secondClaim = (new DueMonitorClaimer($this->clock))->claimDueMonitors(limit: 1);

        $this->assertCount(1, $firstClaim);
        $this->assertCount(0, $secondClaim);
    }

    public function test_reclaims_stale_expired_lease_monitors(): void
    {
        $staleMonitor = Monitor::query()->create([
            'tenant_id' => $this->tenant->id,
            'environment_id' => $this->environment->id,
            'name' => 'Stale Lease Monitor',
            'kind' => 'http',
            'target' => 'http://target:8095/status/200',
            'enabled' => true,
            'next_check_at' => new DateTimeImmutable('2026-08-03 11:50:00 UTC'),
            'claim_token' => 'old_expired_token',
            'claim_expires_at' => new DateTimeImmutable('2026-08-03 11:55:00 UTC'), // expired in past
        ]);

        $claimed = $this->claimer->claimDueMonitors();

        $this->assertCount(1, $claimed);
        $this->assertEquals($staleMonitor->id, $claimed->first()->id);
        $this->assertNotEquals('old_expired_token', $claimed->first()->claim_token);
    }
}
