<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Api\IdempotencyResponse;
use App\Application\Api\IdempotentWriteService;
use App\Application\Incidents\IncidentManagementService;
use App\Domain\Shared\Clock;
use App\Http\Controllers\Controller;
use App\Http\Resources\IncidentResource;
use App\Http\Resources\IncidentUpdateResource;
use App\Infrastructure\Persistence\Eloquent\ApiKey;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IncidentController extends Controller
{
    public function __construct(
        private readonly IncidentManagementService $incidentManagement,
        private readonly IdempotentWriteService $idempotentWrites,
        private readonly Clock $clock,
    ) {}

    public function index(Request $request, string $tenantId, string $environmentId): JsonResponse
    {
        return response()->json([
            'data' => IncidentResource::collection($this->incidentManagement->list($this->tenant($request), $this->environment($request))),
        ]);
    }

    public function show(Request $request, string $tenantId, string $environmentId, string $incidentId): JsonResponse
    {
        return response()->json([
            'data' => new IncidentResource($this->incidentManagement->get($this->tenant($request), $this->environment($request), $incidentId)),
        ]);
    }

    public function store(Request $request, string $tenantId, string $environmentId): JsonResponse
    {
        $validated = $request->validate([
            'summary' => 'required|string|max:2000',
            'severity' => 'required|string|in:minor,major',
        ]);

        return $this->idempotent($request, function () use ($request, $validated): IdempotencyResponse {
            $incident = $this->incidentManagement->createManual(
                $this->tenant($request),
                $this->environment($request),
                $validated['summary'],
                $validated['severity'],
                $this->clock->nowUtc(),
            );

            return new IdempotencyResponse(201, ['data' => (new IncidentResource($incident))->resolve($request)]);
        });
    }

    public function storeUpdate(Request $request, string $tenantId, string $environmentId, string $incidentId): JsonResponse
    {
        $validated = $request->validate([
            'message' => 'required|string|max:5000',
            'status' => 'nullable|string|max:32',
        ]);

        return $this->idempotent($request, function () use ($request, $incidentId, $validated): IdempotencyResponse {
            $update = $this->incidentManagement->addUpdate(
                $this->tenant($request),
                $this->environment($request),
                $incidentId,
                $validated['message'],
                $validated['status'] ?? null,
                $this->clock->nowUtc(),
            );

            return new IdempotencyResponse(201, ['data' => (new IncidentUpdateResource($update))->resolve($request)]);
        });
    }

    public function resolve(Request $request, string $tenantId, string $environmentId, string $incidentId): JsonResponse
    {
        return $this->idempotent($request, function () use ($request, $incidentId): IdempotencyResponse {
            $incident = $this->incidentManagement->resolve(
                $this->tenant($request),
                $this->environment($request),
                $incidentId,
                $this->clock->nowUtc(),
            );

            return new IdempotencyResponse(200, ['data' => (new IncidentResource($incident))->resolve($request)]);
        });
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

    /** @param callable(): IdempotencyResponse $write */
    private function idempotent(Request $request, callable $write): JsonResponse
    {
        $key = trim((string) $request->header('Idempotency-Key'));
        if ($key === '' || strlen($key) > 255) {
            abort(422, __('messages.idempotency_key_required'));
        }

        /** @var ApiKey|null $apiKey */
        $apiKey = $request->attributes->get('api_key');
        $user = $request->user();
        $response = $this->idempotentWrites->handle(
            $this->tenant($request),
            $this->environment($request),
            $user instanceof \App\Infrastructure\Persistence\Eloquent\User ? $user : null,
            $apiKey?->id,
            $key,
            sprintf('%s %s', $request->method(), $request->route()?->uri() ?? $request->path()),
            $request->getContent(),
            $this->clock->nowUtc(),
            $write,
        );

        return response()->json($response->body, $response->statusCode);
    }
}
