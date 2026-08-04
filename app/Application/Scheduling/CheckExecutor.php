<?php

namespace App\Application\Scheduling;

use App\Domain\Monitoring\AssertionDefinition;
use App\Domain\Monitoring\AssertionEvaluator;
use App\Domain\Monitoring\AssertionOperator;
use App\Domain\Monitoring\AssertionType;
use App\Domain\Monitoring\CheckOutcome;
use App\Domain\Monitoring\CheckState;
use App\Domain\Monitoring\MonitorKind;

use App\Domain\Outbound\OutboundPolicy;
use App\Domain\Outbound\OutboundPolicyViolation;
use App\Domain\Secrets\SecretRedactor;
use App\Domain\Shared\Clock;
use App\Infrastructure\HttpClient\PinnedHttpRequest;
use App\Infrastructure\HttpClient\PinnedHttpTransport;
use App\Infrastructure\HttpClient\PinnedHttpResponse;
use App\Infrastructure\Persistence\Eloquent\CheckResult;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use Illuminate\Support\Facades\DB;

class CheckExecutor
{
    private const CHECK_FAILURE_EXCERPT_BYTES = 512;

    public function __construct(
        private readonly OutboundPolicy $outboundPolicy,
        private readonly PinnedHttpTransport $httpTransport,
        private readonly AssertionEvaluator $assertionEvaluator,
        private readonly SecretRedactor $secretRedactor,
        private readonly Clock $clock,
    ) {
    }

    public function execute(Monitor $monitor): ?CheckResult
    {
        $now = $this->clock->nowUtc();
        $claimToken = $monitor->claim_token;

        if ($monitor->kind === MonitorKind::HTTP) {
            $outcome = $this->executeHttpCheck($monitor);
        } else {
            // TCP / Heartbeat stub for PR4
            $outcome = new CheckOutcome(statusCode: 200, latencyMs: 10);
        }

        return $this->persist($monitor, $outcome);
    }

    public function persist(Monitor $monitor, CheckOutcome $outcome): ?CheckResult
    {
        $now = $this->clock->nowUtc();
        $claimToken = $monitor->claim_token;
        $assertionDefs = $monitor->assertions->map(fn ($ast) => new AssertionDefinition(
            type: $ast->type,
            operator: $ast->operator,
            targetProperty: $ast->target_property,
            expectedValue: $ast->expected_value,
            caseSensitive: $ast->case_sensitive,
            order: $ast->order,
        ))->all();

        $evalResult = $this->assertionEvaluator->evaluate($outcome, $assertionDefs);

        return DB::transaction(function () use ($monitor, $outcome, $evalResult, $now, $claimToken) {
            $state = $evalResult->state;

            $consecutiveFailures = in_array($state, [CheckState::DOWN, CheckState::DEGRADED], true)
                ? $monitor->consecutive_failures + 1
                : 0;

            $consecutiveSuccesses = $state === CheckState::UP
                ? $monitor->consecutive_successes + 1
                : 0;

            $monitorUpdate = Monitor::query()->whereKey($monitor->id);
            if ($claimToken !== null) {
                $monitorUpdate->where('claim_token', $claimToken);
            }

            $updated = $monitorUpdate->update([
                'current_state' => $state,
                'consecutive_failures' => $consecutiveFailures,
                'consecutive_successes' => $consecutiveSuccesses,
                'last_checked_at' => $now,
                'last_latency_ms' => $outcome->latencyMs,
                'claim_token' => null,
                'claimed_at' => null,
                'claim_expires_at' => null,
            ]);

            if ($updated === 0) {
                return null;
            }

            $failureExcerpt = null;
            if ($state !== CheckState::UP && $outcome->body !== '') {
                $rawExcerpt = substr($outcome->body, 0, self::CHECK_FAILURE_EXCERPT_BYTES);
                $failureExcerpt = $this->secretRedactor->redactString($rawExcerpt);
            }

            return CheckResult::query()->create([
                'tenant_id' => $monitor->tenant_id,
                'environment_id' => $monitor->environment_id,
                'monitor_id' => $monitor->id,
                'state' => $state,
                'latency_ms' => $outcome->latencyMs,
                'status_code' => $outcome->statusCode > 0 ? $outcome->statusCode : null,
                'failure_reason' => $evalResult->reason,
                'failure_excerpt' => $failureExcerpt,
                'checked_at' => $now,
            ]);
        });
    }

    public function prepareHttp(Monitor $monitor): PinnedHttpRequest|CheckOutcome
    {
        try {
            $validatedEndpoint = $this->outboundPolicy->validateUrl(
                $monitor->target,
                [$monitor->environment?->name ?? 'production'],
                $monitor->egress_profile,
            );
        } catch (OutboundPolicyViolation $violation) {
            return CheckOutcome::blocked($violation->reasonCode);
        }

        return new PinnedHttpRequest(
            method: $monitor->http_method ?: 'GET', endpoint: $validatedEndpoint,
            headers: is_array($monitor->request_headers_json) ? $monitor->request_headers_json : [],
            body: $monitor->request_body, verifyTls: $monitor->verify_tls, followRedirects: $monitor->follow_redirects,
            totalTimeout: (int) ceil($monitor->timeout_ms / 1000),
            egressProfile: \App\Domain\Outbound\EgressProfile::tryFromMixed($monitor->egress_profile),
        );
    }

    public function outcomeFromHttpResponse(PinnedHttpResponse $response, int $latencyMs): CheckOutcome
    {
        if ($response->transportError !== null && str_starts_with($response->transportError, 'blocked:')) {
            return CheckOutcome::blocked(substr($response->transportError, 8));
        }
        return $response->transportError !== null
            ? CheckOutcome::transportError($response->transportError, $latencyMs)
            : new CheckOutcome(statusCode: $response->statusCode, latencyMs: $latencyMs, headers: $response->headers, body: $response->bodyTruncated);
    }

    private function executeHttpCheck(Monitor $monitor): CheckOutcome
    {
        $request = $this->prepareHttp($monitor);
        if ($request instanceof CheckOutcome) return $request;

        $startTime = microtime(true);
        $response = $this->httpTransport->send($request);
        $latencyMs = (int) round((microtime(true) - $startTime) * 1000);

        return $this->outcomeFromHttpResponse($response, $latencyMs);
    }
}
