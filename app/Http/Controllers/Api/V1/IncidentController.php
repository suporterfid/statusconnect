<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Incidents\IncidentManagementService;
use App\Domain\Shared\Clock;
use App\Http\Controllers\Controller;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IncidentController extends Controller
{
    public function __construct(
        private readonly IncidentManagementService $incidentManagement,
        private readonly Clock $clock,
    ) {}

    public function index(Request $request, string $tenantId, string $environmentId): JsonResponse
    {
        return response()->json([
            'data' => $this->incidentManagement->list($this->tenant($request), $this->environment($request)),
        ]);
    }

    public function show(Request $request, string $tenantId, string $environmentId, string $incidentId): JsonResponse
    {
        return response()->json([
            'data' => $this->incidentManagement->get($this->tenant($request), $this->environment($request), $incidentId),
        ]);
    }

    public function store(Request $request, string $tenantId, string $environmentId): JsonResponse
    {
        $validated = $request->validate([
            'summary' => 'required|string|max:2000',
            'severity' => 'required|string|in:minor,major',
        ]);

        $incident = $this->incidentManagement->createManual(
            $this->tenant($request),
            $this->environment($request),
            $validated['summary'],
            $validated['severity'],
            $this->clock->nowUtc(),
        );

        return response()->json(['data' => $incident], 201);
    }

    public function storeUpdate(Request $request, string $tenantId, string $environmentId, string $incidentId): JsonResponse
    {
        $validated = $request->validate([
            'message' => 'required|string|max:5000',
            'status' => 'nullable|string|max:32',
        ]);

        $update = $this->incidentManagement->addUpdate(
            $this->tenant($request),
            $this->environment($request),
            $incidentId,
            $validated['message'],
            $validated['status'] ?? null,
            $this->clock->nowUtc(),
        );

        return response()->json(['data' => $update], 201);
    }

    private function tenant(Request $request): Tenant
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');

        return $tenant;
    }

    private function environment(Request $request): Environment
    {
        /** @var Environment $environment */
        $environment = $request->attributes->get('environment');

        return $environment;
    }
}
