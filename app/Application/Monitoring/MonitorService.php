<?php

namespace App\Application\Monitoring;

use App\Domain\Monitoring\AssertionOperator;
use App\Domain\Monitoring\AssertionType;
use App\Domain\Monitoring\MonitorKind;
use App\Domain\Outbound\OutboundPolicy;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use App\Infrastructure\Persistence\Eloquent\MonitorAssertion;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MonitorService
{
    public function __construct(
        private readonly OutboundPolicy $outboundPolicy,
    ) {
    }

    /**
     * @return Collection<int, Monitor>
     */
    public function listMonitors(Tenant $tenant, Environment $environment): Collection
    {
        return Monitor::query()
            ->where('tenant_id', $tenant->id)
            ->where('environment_id', $environment->id)
            ->with('assertions')
            ->get();
    }

    public function getMonitor(Tenant $tenant, Environment $environment, string $publicId): Monitor
    {
        return Monitor::query()
            ->where('tenant_id', $tenant->id)
            ->where('environment_id', $environment->id)
            ->where('public_id', $publicId)
            ->with('assertions')
            ->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createMonitor(Tenant $tenant, Environment $environment, array $data): Monitor
    {
        $target = (string) ($data['target'] ?? '');
        $kind = MonitorKind::tryFromMixed($data['kind'] ?? null);

        if ($kind === MonitorKind::HTTP) {
            // Validate outbound SSRF policy on monitor target URL
            $this->outboundPolicy->validateUrl($target, [$environment->name]);
        }

        return DB::transaction(function () use ($tenant, $environment, $data, $kind) {
            /** @var Monitor $monitor */
            $monitor = Monitor::create([
                'tenant_id' => $tenant->id,
                'environment_id' => $environment->id,
                'name' => $data['name'],
                'kind' => $kind,
                'target' => $data['target'],
                'http_method' => strtoupper($data['http_method'] ?? 'GET'),
                'request_headers_json' => $data['request_headers_json'] ?? null,
                'request_body' => $data['request_body'] ?? null,
                'interval_seconds' => $data['interval_seconds'] ?? 60,
                'timeout_ms' => $data['timeout_ms'] ?? 10000,
                'confirmation_threshold' => $data['confirmation_threshold'] ?? 2,
                'recovery_threshold' => $data['recovery_threshold'] ?? 2,
                'follow_redirects' => $data['follow_redirects'] ?? true,
                'verify_tls' => $data['verify_tls'] ?? true,
                'egress_profile' => $data['egress_profile'] ?? 'internal',
                'public_safe' => $data['public_safe'] ?? true,
                'enabled' => $data['enabled'] ?? true,
                'next_check_at' => now(),
            ]);

            if (isset($data['assertions']) && is_array($data['assertions'])) {
                $this->saveAssertions($monitor, $data['assertions']);
            } else {
                // Default status code assertion
                MonitorAssertion::create([
                    'monitor_id' => $monitor->id,
                    'type' => AssertionType::STATUS_CODE,
                    'operator' => AssertionOperator::BETWEEN,
                    'expected_value' => '200..299',
                    'order' => 0,
                ]);
            }

            return $monitor->fresh('assertions');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateMonitor(Tenant $tenant, Environment $environment, string $publicId, array $data): Monitor
    {
        $monitor = $this->getMonitor($tenant, $environment, $publicId);

        if (isset($data['target'])) {
            $kind = isset($data['kind']) ? MonitorKind::tryFromMixed($data['kind']) : $monitor->kind;
            if ($kind === MonitorKind::HTTP) {
                $this->outboundPolicy->validateUrl((string) $data['target'], [$environment->name]);
            }
        }

        return DB::transaction(function () use ($monitor, $data) {
            $monitor->update(array_filter([
                'name' => $data['name'] ?? null,
                'kind' => isset($data['kind']) ? MonitorKind::tryFromMixed($data['kind']) : null,
                'target' => $data['target'] ?? null,
                'http_method' => isset($data['http_method']) ? strtoupper($data['http_method']) : null,
                'request_headers_json' => $data['request_headers_json'] ?? null,
                'request_body' => $data['request_body'] ?? null,
                'interval_seconds' => $data['interval_seconds'] ?? null,
                'timeout_ms' => $data['timeout_ms'] ?? null,
                'confirmation_threshold' => $data['confirmation_threshold'] ?? null,
                'recovery_threshold' => $data['recovery_threshold'] ?? null,
                'follow_redirects' => $data['follow_redirects'] ?? null,
                'verify_tls' => $data['verify_tls'] ?? null,
                'egress_profile' => $data['egress_profile'] ?? null,
                'public_safe' => $data['public_safe'] ?? null,
                'enabled' => $data['enabled'] ?? null,
            ], fn ($v) => $v !== null));

            if (isset($data['assertions']) && is_array($data['assertions'])) {
                $monitor->assertions()->delete();
                $this->saveAssertions($monitor, $data['assertions']);
            }

            return $monitor->fresh('assertions');
        });
    }

    public function deleteMonitor(Tenant $tenant, Environment $environment, string $publicId): void
    {
        $monitor = $this->getMonitor($tenant, $environment, $publicId);
        $monitor->delete();
    }

    /**
     * @param  list<array<string, mixed>>  $assertionsData
     */
    private function saveAssertions(Monitor $monitor, array $assertionsData): void
    {
        foreach ($assertionsData as $index => $ast) {
            $type = AssertionType::tryFrom((string) ($ast['type'] ?? ''));
            $operator = AssertionOperator::tryFrom((string) ($ast['operator'] ?? ''));

            if ($type === null || $operator === null) {
                throw ValidationException::withMessages([
                    "assertions.{$index}" => 'Invalid assertion type or operator.',
                ]);
            }

            // Regex ReDoS compile safety check (§7.7)
            if ($type === AssertionType::BODY_MATCHES && $operator === AssertionOperator::REGEX) {
                $pattern = (string) ($ast['expected_value'] ?? '');
                if (strlen($pattern) > 500) {
                    throw ValidationException::withMessages([
                        "assertions.{$index}.expected_value" => 'Regex pattern length must not exceed 500 characters.',
                    ]);
                }
                if (@preg_match($pattern, 'probe') === false) {
                    throw ValidationException::withMessages([
                        "assertions.{$index}.expected_value" => 'Invalid regular expression pattern.',
                    ]);
                }
            }

            MonitorAssertion::create([
                'monitor_id' => $monitor->id,
                'type' => $type,
                'operator' => $operator,
                'target_property' => $ast['target_property'] ?? null,
                'expected_value' => $ast['expected_value'] ?? null,
                'case_sensitive' => $ast['case_sensitive'] ?? false,
                'order' => $index,
            ]);
        }
    }
}
