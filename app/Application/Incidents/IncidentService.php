<?php

namespace App\Application\Incidents;

use App\Domain\Incidents\IncidentAction;
use App\Domain\Incidents\IncidentStateMachine;
use App\Domain\Incidents\MonitorSnapshot;
use App\Domain\Incidents\MonitorTransition;
use App\Domain\Monitoring\CheckOutcome;
use App\Domain\Monitoring\EvaluationResult;
use App\Infrastructure\Persistence\Eloquent\CheckResult;
use App\Infrastructure\Persistence\Eloquent\Incident;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

class IncidentService
{
    public function __construct(private readonly IncidentStateMachine $stateMachine)
    {
    }

    public function record(
        Monitor $monitor,
        CheckOutcome $outcome,
        EvaluationResult $evaluation,
        DateTimeImmutable $checkedAt,
        ?string $failureExcerpt = null,
    ): ?CheckResult {
        $claimToken = $monitor->claim_token;
        $transition = $this->stateMachine->transition(
            new MonitorSnapshot(
                $monitor->current_state,
                (int) $monitor->consecutive_failures,
                (int) $monitor->consecutive_successes,
                $monitor->first_failure_at?->toDateTimeImmutable(),
            ),
            $evaluation->state,
            $checkedAt,
            (int) $monitor->confirmation_threshold,
            (int) $monitor->recovery_threshold,
        );

        return DB::transaction(function () use ($monitor, $outcome, $evaluation, $checkedAt, $failureExcerpt, $claimToken, $transition) {
            $monitorUpdate = Monitor::query()->whereKey($monitor->id);
            if ($claimToken !== null) {
                $monitorUpdate->where('claim_token', $claimToken);
            }

            $updated = $monitorUpdate->update([
                'current_state' => $transition->nextState,
                'consecutive_failures' => $transition->consecutiveFailures,
                'consecutive_successes' => $transition->consecutiveSuccesses,
                'first_failure_at' => $transition->firstFailureAt,
                'last_checked_at' => $checkedAt,
                'last_latency_ms' => $outcome->latencyMs,
                'claim_token' => null,
                'claimed_at' => null,
                'claim_expires_at' => null,
            ]);

            if ($updated === 0) {
                return null;
            }

            $result = CheckResult::query()->create([
                'tenant_id' => $monitor->tenant_id,
                'environment_id' => $monitor->environment_id,
                'monitor_id' => $monitor->id,
                'state' => $evaluation->state,
                'latency_ms' => $outcome->latencyMs,
                'status_code' => $outcome->statusCode > 0 ? $outcome->statusCode : null,
                'failure_reason' => $evaluation->reason,
                'failure_excerpt' => $failureExcerpt,
                'checked_at' => $checkedAt,
            ]);

            match ($transition->action) {
                IncidentAction::OPEN => $this->openIncident($monitor, $transition, $evaluation, $checkedAt),
                IncidentAction::RESOLVE => $this->resolveAutomaticIncident($monitor, $checkedAt),
                default => null,
            };

            return $result;
        });
    }

    private function openIncident(
        Monitor $monitor,
        MonitorTransition $transition,
        EvaluationResult $evaluation,
        DateTimeImmutable $checkedAt,
    ): Incident {
        return Incident::query()->firstOrCreate(
            ['monitor_id' => $monitor->id, 'resolved_flag' => false],
            [
                'tenant_id' => $monitor->tenant_id,
                'environment_id' => $monitor->environment_id,
                'manual' => false,
                'started_at' => $transition->firstFailureAt,
                'confirmed_at' => $checkedAt,
                'severity' => $transition->nextState->value === 'degraded' ? 'minor' : 'major',
                'summary' => $evaluation->reason ?? 'Monitor check failed',
            ],
        );
    }

    private function resolveAutomaticIncident(Monitor $monitor, DateTimeImmutable $resolvedAt): void
    {
        $incident = Incident::query()
            ->where('monitor_id', $monitor->id)
            ->where('manual', false)
            ->where('resolved_flag', false)
            ->lockForUpdate()
            ->first();

        if ($incident === null) {
            return;
        }

        $incident->update([
            'resolved_at' => $resolvedAt,
            'resolved_flag' => null,
            'duration_seconds' => max(0, $resolvedAt->getTimestamp() - $incident->started_at->getTimestamp()),
        ]);
    }
}
