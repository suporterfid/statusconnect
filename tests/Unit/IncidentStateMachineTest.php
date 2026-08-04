<?php

namespace Tests\Unit;

use App\Domain\Incidents\IncidentAction;
use App\Domain\Incidents\IncidentStateMachine;
use App\Domain\Incidents\MonitorSnapshot;
use App\Domain\Monitoring\CheckState;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class IncidentStateMachineTest extends TestCase
{
    public function test_failure_below_confirmation_threshold_keeps_the_confirmed_state(): void
    {
        $transition = (new IncidentStateMachine())->transition(
            new MonitorSnapshot(CheckState::UP, 1, 0, new DateTimeImmutable('2026-08-04T10:00:00Z')),
            CheckState::DEGRADED,
            new DateTimeImmutable('2026-08-04T10:01:00Z'),
            confirmationThreshold: 3,
            recoveryThreshold: 2,
        );

        $this->assertSame(CheckState::UP, $transition->nextState);
        $this->assertSame(2, $transition->consecutiveFailures);
        $this->assertSame(IncidentAction::NONE, $transition->action);
        $this->assertEquals(new DateTimeImmutable('2026-08-04T10:00:00Z'), $transition->firstFailureAt);
    }

    public function test_confirmation_opens_with_the_first_failure_timestamp(): void
    {
        $transition = (new IncidentStateMachine())->transition(
            new MonitorSnapshot(CheckState::UP, 2, 0, new DateTimeImmutable('2026-08-04T10:00:00Z')),
            CheckState::DOWN,
            new DateTimeImmutable('2026-08-04T10:02:00Z'),
            confirmationThreshold: 3,
            recoveryThreshold: 2,
        );

        $this->assertSame(IncidentAction::OPEN, $transition->action);
        $this->assertSame(CheckState::DOWN, $transition->nextState);
        $this->assertEquals(new DateTimeImmutable('2026-08-04T10:00:00Z'), $transition->firstFailureAt);
    }

    public function test_blocked_does_not_increment_failures_or_open_an_incident(): void
    {
        $transition = (new IncidentStateMachine())->transition(
            new MonitorSnapshot(CheckState::UP, 1, 0, new DateTimeImmutable('2026-08-04T10:00:00Z')),
            CheckState::BLOCKED,
            new DateTimeImmutable('2026-08-04T10:01:00Z'),
            confirmationThreshold: 3,
            recoveryThreshold: 2,
        );

        $this->assertSame(1, $transition->consecutiveFailures);
        $this->assertSame(0, $transition->consecutiveSuccesses);
        $this->assertSame(CheckState::UP, $transition->nextState);
        $this->assertSame(IncidentAction::CONFIGURATION_FAULT, $transition->action);
    }

    public function test_recovery_resolves_only_at_its_threshold(): void
    {
        $machine = new IncidentStateMachine();
        $monitor = new MonitorSnapshot(CheckState::DOWN, 4, 0, new DateTimeImmutable('2026-08-04T10:00:00Z'));

        $firstSuccess = $machine->transition(
            $monitor,
            CheckState::UP,
            new DateTimeImmutable('2026-08-04T10:04:00Z'),
            confirmationThreshold: 3,
            recoveryThreshold: 2,
        );
        $recovered = $machine->transition(
            new MonitorSnapshot($firstSuccess->nextState, $firstSuccess->consecutiveFailures, $firstSuccess->consecutiveSuccesses, $firstSuccess->firstFailureAt),
            CheckState::UP,
            new DateTimeImmutable('2026-08-04T10:05:00Z'),
            confirmationThreshold: 3,
            recoveryThreshold: 2,
        );

        $this->assertSame(CheckState::DOWN, $firstSuccess->nextState);
        $this->assertSame(IncidentAction::NONE, $firstSuccess->action);
        $this->assertSame(CheckState::UP, $recovered->nextState);
        $this->assertSame(IncidentAction::RESOLVE, $recovered->action);
        $this->assertNull($recovered->firstFailureAt);
    }

    public function test_degraded_confirmation_opens_a_minor_incident(): void
    {
        $transition = (new IncidentStateMachine())->transition(
            new MonitorSnapshot(CheckState::UP, 2, 0, new DateTimeImmutable('2026-08-04T10:00:00Z')),
            CheckState::DEGRADED,
            new DateTimeImmutable('2026-08-04T10:02:00Z'),
            confirmationThreshold: 3,
            recoveryThreshold: 2,
        );

        $this->assertSame(CheckState::DEGRADED, $transition->nextState);
        $this->assertSame(IncidentAction::OPEN, $transition->action);
    }

    public function test_pending_becomes_up_after_its_first_successful_check(): void
    {
        $transition = (new IncidentStateMachine())->transition(
            new MonitorSnapshot(CheckState::PENDING, 0, 0, null),
            CheckState::UP,
            new DateTimeImmutable('2026-08-04T10:00:00Z'),
            confirmationThreshold: 3,
            recoveryThreshold: 2,
        );

        $this->assertSame(CheckState::UP, $transition->nextState);
        $this->assertSame(IncidentAction::NONE, $transition->action);
    }

    public function test_success_resets_an_unconfirmed_failure_streak(): void
    {
        $transition = (new IncidentStateMachine())->transition(
            new MonitorSnapshot(CheckState::UP, 2, 0, new DateTimeImmutable('2026-08-04T10:00:00Z')),
            CheckState::UP,
            new DateTimeImmutable('2026-08-04T10:02:00Z'),
            confirmationThreshold: 3,
            recoveryThreshold: 2,
        );

        $this->assertSame(CheckState::UP, $transition->nextState);
        $this->assertSame(0, $transition->consecutiveFailures);
        $this->assertSame(1, $transition->consecutiveSuccesses);
        $this->assertNull($transition->firstFailureAt);
    }

    public function test_failure_above_the_confirmation_threshold_does_not_open_a_second_incident(): void
    {
        $transition = (new IncidentStateMachine())->transition(
            new MonitorSnapshot(CheckState::DOWN, 3, 0, new DateTimeImmutable('2026-08-04T10:00:00Z')),
            CheckState::DOWN,
            new DateTimeImmutable('2026-08-04T10:03:00Z'),
            confirmationThreshold: 3,
            recoveryThreshold: 2,
        );

        $this->assertSame(CheckState::DOWN, $transition->nextState);
        $this->assertSame(4, $transition->consecutiveFailures);
        $this->assertSame(IncidentAction::NONE, $transition->action);
    }

    public function test_skipped_paused_and_maintenance_outcomes_preserve_the_confirmed_snapshot(): void
    {
        $snapshot = new MonitorSnapshot(CheckState::UP, 1, 2, new DateTimeImmutable('2026-08-04T10:00:00Z'));

        foreach ([CheckState::SKIPPED, CheckState::PAUSED, CheckState::MAINTENANCE] as $outcome) {
            $transition = (new IncidentStateMachine())->transition(
                $snapshot,
                $outcome,
                new DateTimeImmutable('2026-08-04T10:01:00Z'),
                confirmationThreshold: 3,
                recoveryThreshold: 2,
            );

            $this->assertSame(CheckState::UP, $transition->nextState);
            $this->assertSame(1, $transition->consecutiveFailures);
            $this->assertSame(2, $transition->consecutiveSuccesses);
            $this->assertSame(IncidentAction::NONE, $transition->action);
        }
    }
}
