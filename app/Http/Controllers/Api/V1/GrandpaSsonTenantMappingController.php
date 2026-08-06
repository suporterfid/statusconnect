<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\GrandpaSson\GrandpaSsonTenantMappingService;
use App\Http\Controllers\Controller;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GrandpaSsonTenantMappingController extends Controller
{
    public function __construct(
        private readonly GrandpaSsonTenantMappingService $mappings,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->isPlatformAdmin()) {
            abort(403);
        }

        $validated = $request->validate([
            'broker_tenant_id' => 'required|string|max:255',
            'tenant_public_id' => 'required|string|max:64',
            'role_mappings' => 'required|array',
            'role_mappings.*' => 'required|string|in:owner,admin,viewer',
            'group_mappings' => 'required|array',
            'group_mappings.*' => 'required|string|in:owner,admin,viewer',
        ]);

        if (array_diff(array_keys($validated['role_mappings']), ['owner', 'admin', 'member']) !== []) {
            abort(422, __('messages.invalid_broker_role_mapping'));
        }

        $tenant = Tenant::query()->where('public_id', $validated['tenant_public_id'])->firstOrFail();
        $mapping = $this->mappings->upsert(
            actor: $actor,
            brokerTenantId: $validated['broker_tenant_id'],
            tenant: $tenant,
            roleMappings: $validated['role_mappings'],
            groupMappings: $validated['group_mappings'],
        );

        return response()->json([
            'data' => [
                'public_id' => $mapping->public_id,
                'broker_tenant_id' => $mapping->broker_tenant_id,
                'tenant_public_id' => $tenant->public_id,
                'role_mappings' => $mapping->role_mappings,
                'group_mappings' => $mapping->group_mappings,
            ],
        ], 201);
    }
}
