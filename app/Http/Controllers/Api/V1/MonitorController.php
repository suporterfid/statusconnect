<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Monitoring\MonitorService;
use App\Http\Controllers\Controller;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MonitorController extends Controller
{
    public function __construct(
        private readonly MonitorService $monitorService,
    ) {
    }

    public function index(Request $request, string $tenantId, string $environmentId): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');
        /** @var Environment $environment */
        $environment = $request->attributes->get('environment');

        $monitors = $this->monitorService->listMonitors($tenant, $environment);

        return response()->json([
            'data' => $monitors,
        ]);
    }

    public function store(Request $request, string $tenantId, string $environmentId): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');
        /** @var Environment $environment */
        $environment = $request->attributes->get('environment');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'kind' => 'nullable|string|in:http,tcp,heartbeat',
            'target' => 'required|string|max:1024',
            'http_method' => 'nullable|string|in:GET,POST,PUT,PATCH,DELETE,HEAD,OPTIONS',
            'request_headers_json' => 'nullable|array',
            'request_body' => 'nullable|string',
            'interval_seconds' => 'nullable|integer|min:10|max:86400',
            'timeout_ms' => 'nullable|integer|min:500|max:30000',
            'confirmation_threshold' => 'nullable|integer|min:1|max:10',
            'recovery_threshold' => 'nullable|integer|min:1|max:10',
            'follow_redirects' => 'nullable|boolean',
            'verify_tls' => 'nullable|boolean',
            'egress_profile' => 'nullable|string|in:internal,public-crawl,api',
            'public_safe' => 'nullable|boolean',
            'enabled' => 'nullable|boolean',
            'assertions' => 'nullable|array',
            'assertions.*.type' => 'required_with:assertions|string',
            'assertions.*.operator' => 'required_with:assertions|string',
            'assertions.*.target_property' => 'nullable|string',
            'assertions.*.expected_value' => 'nullable|string',
            'assertions.*.case_sensitive' => 'nullable|boolean',
        ]);

        $monitor = $this->monitorService->createMonitor($tenant, $environment, $validated);

        return response()->json([
            'data' => $monitor,
        ], 201);
    }

    public function show(Request $request, string $tenantId, string $environmentId, string $monitorId): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');
        /** @var Environment $environment */
        $environment = $request->attributes->get('environment');

        $monitor = $this->monitorService->getMonitor($tenant, $environment, $monitorId);

        return response()->json([
            'data' => $monitor,
        ]);
    }

    public function update(Request $request, string $tenantId, string $environmentId, string $monitorId): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');
        /** @var Environment $environment */
        $environment = $request->attributes->get('environment');

        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'kind' => 'nullable|string|in:http,tcp,heartbeat',
            'target' => 'nullable|string|max:1024',
            'http_method' => 'nullable|string|in:GET,POST,PUT,PATCH,DELETE,HEAD,OPTIONS',
            'request_headers_json' => 'nullable|array',
            'request_body' => 'nullable|string',
            'interval_seconds' => 'nullable|integer|min:10|max:86400',
            'timeout_ms' => 'nullable|integer|min:500|max:30000',
            'confirmation_threshold' => 'nullable|integer|min:1|max:10',
            'recovery_threshold' => 'nullable|integer|min:1|max:10',
            'follow_redirects' => 'nullable|boolean',
            'verify_tls' => 'nullable|boolean',
            'egress_profile' => 'nullable|string|in:internal,public-crawl,api',
            'public_safe' => 'nullable|boolean',
            'enabled' => 'nullable|boolean',
            'assertions' => 'nullable|array',
            'assertions.*.type' => 'required_with:assertions|string',
            'assertions.*.operator' => 'required_with:assertions|string',
            'assertions.*.target_property' => 'nullable|string',
            'assertions.*.expected_value' => 'nullable|string',
            'assertions.*.case_sensitive' => 'nullable|boolean',
        ]);

        $monitor = $this->monitorService->updateMonitor($tenant, $environment, $monitorId, $validated);

        return response()->json([
            'data' => $monitor,
        ]);
    }

    public function destroy(Request $request, string $tenantId, string $environmentId, string $monitorId): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');
        /** @var Environment $environment */
        $environment = $request->attributes->get('environment');

        $this->monitorService->deleteMonitor($tenant, $environment, $monitorId);

        return response()->json(null, 204);
    }
}
