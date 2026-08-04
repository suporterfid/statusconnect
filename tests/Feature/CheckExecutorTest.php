<?php

namespace Tests\Feature;

use App\Application\Scheduling\CheckExecutor;
use App\Domain\Monitoring\AssertionOperator;
use App\Domain\Monitoring\AssertionType;
use App\Domain\Monitoring\CheckState;
use App\Domain\Monitoring\CheckOutcome;
use App\Domain\Shared\FrozenClock;
use App\Infrastructure\HttpClient\PinnedHttpRequest;
use App\Infrastructure\HttpClient\PinnedHttpResponse;
use App\Infrastructure\HttpClient\PinnedHttpTransport;

use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\CheckResult;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use App\Infrastructure\Persistence\Eloquent\MonitorAssertion;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckExecutorTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Environment $environment;

    private CheckExecutor $executor;

    protected function setUp(): void
    {
        parent::setUp();

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

        $this->executor = app(CheckExecutor::class);
    }

    public function test_executes_successful_check_against_target_mock(): void
    {
        /** @var Monitor $monitor */
        $monitor = Monitor::query()->create([
            'tenant_id' => $this->tenant->id,
            'environment_id' => $this->environment->id,
            'name' => 'Target 200 Check',
            'kind' => 'http',
            'target' => 'http://target:8080/status/200',
            'interval_seconds' => 60,
            'enabled' => true,
        ]);

        MonitorAssertion::query()->create([
            'monitor_id' => $monitor->id,
            'type' => AssertionType::STATUS_CODE,
            'operator' => AssertionOperator::EQUALS,
            'expected_value' => '200',
            'order' => 0,
        ]);

        $result = $this->executor->execute($monitor->fresh('assertions'));

        $this->assertEquals(CheckState::UP, $result->state);
        $this->assertEquals(200, $result->status_code);
        $this->assertNull($result->failure_reason);

        $freshMonitor = $monitor->fresh();
        $this->assertEquals(CheckState::UP, $freshMonitor->current_state);
        $this->assertEquals(1, $freshMonitor->consecutive_successes);
        $this->assertEquals(0, $freshMonitor->consecutive_failures);
        $this->assertNull($freshMonitor->claim_token);
    }

    public function test_executes_failing_check_with_excerpt_and_failure_count(): void
    {
        /** @var Monitor $monitor */
        $monitor = Monitor::query()->create([
            'tenant_id' => $this->tenant->id,
            'environment_id' => $this->environment->id,
            'name' => 'Target 500 Check',
            'kind' => 'http',
            'target' => 'http://target:8080/status/500',
            'interval_seconds' => 60,
            'enabled' => true,
        ]);

        MonitorAssertion::query()->create([
            'monitor_id' => $monitor->id,
            'type' => AssertionType::STATUS_CODE,
            'operator' => AssertionOperator::EQUALS,
            'expected_value' => '200',
            'order' => 0,
        ]);

        $result = $this->executor->execute($monitor->fresh('assertions'));

        $this->assertEquals(CheckState::DOWN, $result->state);
        $this->assertEquals(500, $result->status_code);
        $this->assertNotNull($result->failure_reason);

        $freshMonitor = $monitor->fresh();
        $this->assertEquals(CheckState::UP, $freshMonitor->current_state);
        $this->assertEquals(1, $freshMonitor->consecutive_failures);
        $this->assertEquals(0, $freshMonitor->consecutive_successes);
    }

    public function test_stale_executor_does_not_clear_a_newer_claim_or_write_a_result(): void
    {
        /** @var Monitor $monitor */
        $monitor = Monitor::query()->create([
            'tenant_id' => $this->tenant->id,
            'environment_id' => $this->environment->id,
            'name' => 'Fenced Monitor',
            'kind' => 'http',
            'target' => 'http://target:8080/status/200',
            'claim_token' => 'old-claim',
            'claimed_at' => now()->subMinute(),
            'claim_expires_at' => now()->addMinutes(5),
        ]);
        $transport = new class($monitor) implements PinnedHttpTransport
        {
            public function __construct(private readonly Monitor $monitor)
            {
            }

            public function send(PinnedHttpRequest $request): PinnedHttpResponse
            {
                Monitor::query()->whereKey($this->monitor->id)->update([
                    'claim_token' => 'new-claim',
                    'claimed_at' => now(),
                    'claim_expires_at' => now()->addMinutes(5),
                ]);

                return new PinnedHttpResponse(
                    statusCode: 200,
                    headers: [],
                    bodyTruncated: '',
                    bodySha256: hash('sha256', ''),
                    bodyTruncatedFlag: false,
                    finalUrl: $request->endpoint->url,
                    redirectCount: 0,
                );
            }
        };
        $executor = new CheckExecutor(
            app(\App\Domain\Outbound\OutboundPolicy::class),
            $transport,
            app(\App\Domain\Monitoring\AssertionEvaluator::class),
            app(\App\Domain\Secrets\SecretRedactor::class),
            new FrozenClock(new \DateTimeImmutable('2026-08-03 12:00:00 UTC')),
            app(\App\Application\Incidents\IncidentService::class),
        );

        $result = $executor->execute($monitor->fresh('assertions'));

        $this->assertNull($result);
        $this->assertSame('new-claim', $monitor->fresh()->claim_token);
        $this->assertSame(0, CheckResult::query()->where('monitor_id', $monitor->id)->count());
    }

    public function test_persists_a_completed_outcome_with_the_monitor_claim_fence(): void
    {
        $monitor = Monitor::query()->create([
            'tenant_id' => $this->tenant->id,
            'environment_id' => $this->environment->id,
            'name' => 'Completed wave check',
            'kind' => 'http',
            'target' => 'http://target:8080/status/200',
            'claim_token' => 'wave-claim',
        ]);

        $result = $this->executor->persist($monitor->fresh('assertions'), new CheckOutcome(statusCode: 200, latencyMs: 12));

        $this->assertSame(CheckState::UP, $result->state);
        $this->assertNull($monitor->fresh()->claim_token);
    }
}
