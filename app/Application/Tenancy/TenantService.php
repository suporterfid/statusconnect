<?php

namespace App\Application\Tenancy;

use App\Domain\Shared\TenantRole;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Persistence\Eloquent\TenantMembership;
use App\Infrastructure\Persistence\Eloquent\User;

final class TenantService
{
    /**
     * Create a new Tenant with a primary owner user and default 'production' environment.
     */
    public function createTenant(string $name, string $slug, User $owner): Tenant
    {
        $tenant = Tenant::query()->create([
            'name' => $name,
            'slug' => $slug,
        ]);

        TenantMembership::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $owner->id,
            'role' => TenantRole::OWNER->value,
        ]);

        Environment::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Production',
            'slug' => 'production',
        ]);

        return $tenant;
    }

    public function addMember(Tenant $tenant, User $user, TenantRole $role): TenantMembership
    {
        return TenantMembership::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => $role->value,
        ]);
    }
}
