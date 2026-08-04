<?php

namespace App\Application\GrandpaSson;

use App\Domain\Shared\TenantRole;
use App\Infrastructure\Persistence\Eloquent\GrandpaSsonTenantMapping;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

final class GrandpaSsonTenantMappingService
{
    /**
     * @param  array<string, string>  $roleMappings
     * @param  array<string, string>  $groupMappings
     */
    public function upsert(
        User $actor,
        string $brokerTenantId,
        Tenant $tenant,
        array $roleMappings,
        array $groupMappings,
    ): GrandpaSsonTenantMapping {
        if (! $actor->isPlatformAdmin()) {
            throw new AuthorizationException('Only platform administrators can manage GrandpaSSOn tenant mappings.');
        }

        $brokerTenantId = trim($brokerTenantId);
        if ($brokerTenantId === '') {
            throw new InvalidArgumentException('The broker tenant id is required.');
        }

        $roleMappings = $this->normalizeRoleMappings($roleMappings);
        $groupMappings = $this->normalizeGroupMappings($groupMappings);

        $mapping = GrandpaSsonTenantMapping::query()->firstOrNew([
            'broker_tenant_id' => $brokerTenantId,
        ]);
        $mapping->fill([
            'tenant_id' => $tenant->id,
            'role_mappings' => $roleMappings,
            'group_mappings' => $groupMappings,
            'updated_by' => $actor->id,
        ]);

        if (! $mapping->exists) {
            $mapping->created_by = $actor->id;
        }

        $mapping->save();

        return $mapping->fresh();
    }

    /**
     * Returns null when no explicit mapping applies or configured mappings disagree.
     *
     * @param  list<string>  $groups
     */
    public function resolve(string $brokerTenantId, string $brokerRole, array $groups): ?ResolvedGrandpaSsonTenantAccess
    {
        $mapping = GrandpaSsonTenantMapping::query()
            ->with('tenant')
            ->where('broker_tenant_id', $brokerTenantId)
            ->first();

        if ($mapping === null) {
            return null;
        }

        $roles = [];
        $roleMappings = $mapping->role_mappings;
        if (isset($roleMappings[$brokerRole])) {
            $roles[] = $roleMappings[$brokerRole];
        }

        $groupMappings = $mapping->group_mappings;
        foreach ($groups as $group) {
            if (isset($groupMappings[$group])) {
                $roles[] = $groupMappings[$group];
            }
        }

        $roles = array_values(array_unique($roles));
        if (count($roles) !== 1) {
            return null;
        }

        $role = TenantRole::tryFrom($roles[0]);
        if ($role === null || $mapping->tenant === null) {
            return null;
        }

        return new ResolvedGrandpaSsonTenantAccess($mapping->tenant, $role);
    }

    /** @param array<string, string> $mappings */
    private function normalizeRoleMappings(array $mappings): array
    {
        $allowedBrokerRoles = ['owner', 'admin', 'member'];

        foreach ($mappings as $brokerRole => $localRole) {
            if (! in_array($brokerRole, $allowedBrokerRoles, true) || TenantRole::tryFrom($localRole) === null) {
                throw new InvalidArgumentException('Invalid GrandpaSSOn broker role mapping.');
            }
        }

        return $mappings;
    }

    /** @param array<string, string> $mappings */
    private function normalizeGroupMappings(array $mappings): array
    {
        foreach ($mappings as $group => $localRole) {
            if (trim($group) === '' || TenantRole::tryFrom($localRole) === null) {
                throw new InvalidArgumentException('Invalid GrandpaSSOn group mapping.');
            }
        }

        return $mappings;
    }
}
