<?php

namespace App\Application\Scheduling;

use App\Domain\Monitoring\CheckOutcome;
use App\Domain\Monitoring\MonitorKind;
use App\Domain\Outbound\OutboundPolicyViolation;
use App\Domain\Outbound\TcpTargetValidator;
use App\Infrastructure\HttpClient\MultiPinnedHttpProbe;
use App\Infrastructure\HttpClient\MultiPinnedHttpRequest;
use App\Infrastructure\Tcp\PinnedTcpProbe;
use App\Infrastructure\Tcp\PinnedTcpRequest;

final class ParallelMonitorCheckRunner
{
    public function __construct(
        private readonly DueMonitorClaimer $claimer,
        private readonly CheckExecutor $executor,
        private readonly MultiPinnedHttpProbe $httpProbe,
        private readonly PinnedTcpProbe $tcpProbe,
        private readonly TcpTargetValidator $tcpTargetValidator,
        private readonly HeartbeatWriter $heartbeatWriter,
    ) {
    }

    /** @return array{claimed:int, executed:int, budget_stopped:bool} */
    public function run(?TickBudget $budget = null): array
    {
        $budget ??= TickBudget::fromConfig();
        $limits = SchedulerLimits::fromConfig();
        $claimed = 0;
        $executed = 0;
        $claimedIds = [];
        $reserve = max(0, (int) config('scheduler.execution_reserve_seconds', 1));
        $stopped = false;

        while ($budget->canClaimMore()) {
            $maxTimeoutMs = (int) floor(($budget->remainingSeconds() - $reserve) * 1000);
            if ($maxTimeoutMs <= 0) { $stopped = true; break; }
            $wave = $this->claimer->claimDueMonitors(
                limit: $limits->waveSize,
                excludedMonitorIds: $claimedIds,
                maxTimeoutMs: $maxTimeoutMs,
            );
            if ($wave->isEmpty()) break;
            $claimed += $wave->count();
            $claimedIds = [...$claimedIds, ...$wave->pluck('id')->all()];
            $requests = [];
            $tcpRequests = [];
            $blocked = [];
            foreach ($wave as $monitor) {
                if ($monitor->kind === MonitorKind::HEARTBEAT) {
                    $blocked[] = [$monitor, new CheckOutcome(statusCode: 200)];
                    continue;
                }
                if ($monitor->kind === MonitorKind::TCP) {
                    try {
                        $tcpRequests[] = new PinnedTcpRequest($monitor->id, $this->tcpTargetValidator->validate($monitor->target), $monitor->timeout_ms);
                    } catch (OutboundPolicyViolation $violation) {
                        $blocked[] = [$monitor, CheckOutcome::blocked($violation->reasonCode)];
                    }
                    continue;
                }
                $prepared = $this->executor->prepareHttp($monitor);
                if ($prepared instanceof CheckOutcome) { $blocked[] = [$monitor, $prepared]; continue; }
                $requests[] = new MultiPinnedHttpRequest($monitor->id, $prepared);
            }
            $tcpBatch = $this->tcpProbe instanceof \App\Infrastructure\Tcp\StreamSelectPinnedTcpProbe
                ? $this->tcpProbe->start($tcpRequests)
                : null;
            foreach ($blocked as [$monitor, $outcome]) if ($this->executor->persist($monitor, $outcome) !== null) $executed++;
            $byId = $wave->keyBy('id');
            foreach ($this->httpProbe->probe($requests) as $result) {
                $monitor = $byId->get($result->monitorId);
                if ($monitor !== null && $this->executor->persist($monitor, $this->executor->outcomeFromHttpResponse($result->response, $result->latencyMs)) !== null) $executed++;
            }
            $tcpResults = $tcpBatch === null ? $this->tcpProbe->probe($tcpRequests) : $this->tcpProbe->finish($tcpBatch, hrtime(true) + ((int) floor($budget->remainingSeconds() * 1_000_000_000)));
            foreach ($tcpResults as $result) {
                $monitor = $byId->get($result->monitorId);
                $outcome = $result->connected
                    ? new CheckOutcome(statusCode: 200, latencyMs: $result->latencyMs)
                    : CheckOutcome::transportError($result->error ?? 'tcp_connect_error', $result->latencyMs);
                if ($monitor !== null && $this->executor->persist($monitor, $outcome) !== null) $executed++;
            }
            if (! $budget->canClaimMore()) $stopped = true;
        }
        $stopped = $stopped || $budget->exhausted();
        $this->heartbeatWriter->record('checker', compact('claimed', 'executed') + ['budget_stopped' => $stopped]);
        return compact('claimed', 'executed') + ['budget_stopped' => $stopped];
    }
}
