<?php

namespace Tests\Unit;

use App\Domain\Monitoring\AssertionDefinition;
use App\Domain\Monitoring\AssertionEvaluator;
use App\Domain\Monitoring\AssertionOperator;
use App\Domain\Monitoring\AssertionType;
use App\Domain\Monitoring\CheckOutcome;
use App\Domain\Monitoring\CheckState;
use App\Domain\Shared\FrozenClock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class AssertionEvaluatorTest extends TestCase
{
    private AssertionEvaluator $evaluator;

    private FrozenClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-08-03 12:00:00 UTC'));
        $this->evaluator = new AssertionEvaluator($this->clock);
    }

    public function test_default_status_code_pass_200_to_299(): void
    {
        $outcome = new CheckOutcome(statusCode: 200, latencyMs: 150);
        $result = $this->evaluator->evaluate($outcome, []);

        $this->assertEquals(CheckState::UP, $result->state);
    }

    public function test_default_status_code_fail_500(): void
    {
        $outcome = new CheckOutcome(statusCode: 500, latencyMs: 150);
        $result = $this->evaluator->evaluate($outcome, []);

        $this->assertEquals(CheckState::DOWN, $result->state);
    }

    public function test_blocked_outcome_returns_blocked_state(): void
    {
        $outcome = CheckOutcome::blocked('Loopback IP blocked');
        $result = $this->evaluator->evaluate($outcome, []);

        $this->assertEquals(CheckState::BLOCKED, $result->state);
    }

    public function test_transport_error_returns_down_state(): void
    {
        $outcome = CheckOutcome::transportError('Connection timed out', 5000);
        $result = $this->evaluator->evaluate($outcome, []);

        $this->assertEquals(CheckState::DOWN, $result->state);
    }

    public function test_latency_failure_returns_degraded_state(): void
    {
        $outcome = new CheckOutcome(statusCode: 200, latencyMs: 1200);
        $assertion = new AssertionDefinition(
            type: AssertionType::LATENCY_MS,
            operator: AssertionOperator::LT,
            expectedValue: '1000',
        );

        $result = $this->evaluator->evaluate($outcome, [$assertion]);

        $this->assertEquals(CheckState::DEGRADED, $result->state);
    }

    public function test_body_contains_assertion(): void
    {
        $outcome = new CheckOutcome(statusCode: 200, body: '{"status":"healthy","version":"1.0"}');
        $assertion = new AssertionDefinition(
            type: AssertionType::BODY_CONTAINS,
            operator: AssertionOperator::CONTAINS,
            expectedValue: 'healthy',
        );

        $result = $this->evaluator->evaluate($outcome, [$assertion]);

        $this->assertEquals(CheckState::UP, $result->state);
    }

    public function test_body_matches_regex_assertion(): void
    {
        $outcome = new CheckOutcome(statusCode: 200, body: 'User count: 42 active');
        $assertion = new AssertionDefinition(
            type: AssertionType::BODY_MATCHES,
            operator: AssertionOperator::REGEX,
            expectedValue: '/User count: \d+ active/',
        );

        $result = $this->evaluator->evaluate($outcome, [$assertion]);

        $this->assertEquals(CheckState::UP, $result->state);
    }

    public function test_header_assertion(): void
    {
        $outcome = new CheckOutcome(statusCode: 200, headers: ['content-type' => 'application/json; charset=utf-8']);
        $assertion = new AssertionDefinition(
            type: AssertionType::HEADER,
            operator: AssertionOperator::CONTAINS,
            targetProperty: 'Content-Type',
            expectedValue: 'application/json',
        );

        $result = $this->evaluator->evaluate($outcome, [$assertion]);

        $this->assertEquals(CheckState::UP, $result->state);
    }

    public function test_json_path_assertion(): void
    {
        $outcome = new CheckOutcome(statusCode: 200, body: '{"data":{"system":{"status":"ok"}}}');
        $assertion = new AssertionDefinition(
            type: AssertionType::JSON_PATH,
            operator: AssertionOperator::EQUALS,
            targetProperty: 'data.system.status',
            expectedValue: 'ok',
        );

        $result = $this->evaluator->evaluate($outcome, [$assertion]);

        $this->assertEquals(CheckState::UP, $result->state);
    }

    public function test_tls_expires_in_days_assertion(): void
    {
        $expiresAt = new DateTimeImmutable('2026-08-30 12:00:00 UTC');
        $outcome = new CheckOutcome(statusCode: 200, tlsExpiresAt: $expiresAt);
        $assertion = new AssertionDefinition(
            type: AssertionType::TLS_EXPIRES_IN_DAYS,
            operator: AssertionOperator::GTE,
            expectedValue: '14',
        );

        $result = $this->evaluator->evaluate($outcome, [$assertion]);

        $this->assertEquals(CheckState::UP, $result->state);
    }
}
