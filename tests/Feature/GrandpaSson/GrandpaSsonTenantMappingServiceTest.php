<?php

namespace Tests\Feature\GrandpaSson;

use App\Application\GrandpaSson\GrandpaSsonTenantMappingService;
use App\Application\Tenancy\TenantService;
use App\Domain\Shared\TenantRole;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GrandpaSsonTenantMappingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_can_map_a_broker_tenant_and_resolve_an_agreeing_role_and_group(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $tenant = app(TenantService::class)->createTenant('Mapped Tenant', 'mapped-tenant', $admin);
        $service = app(GrandpaSsonTenantMappingService::class);

        $service->upsert(
            actor: $admin,
            brokerTenantId: 'gss_tenant_acme',
            tenant: $tenant,
            roleMappings: ['admin' => 'admin', 'member' => 'viewer'],
            groupMappings: ['editors' => 'admin'],
        );

        $access = $service->resolve('gss_tenant_acme', 'admin', ['unmapped', 'editors']);

        $this->assertNotNull($access);
        $this->assertSame($tenant->id, $access->tenant->id);
        $this->assertSame(TenantRole::ADMIN, $access->role);
    }

    public function test_conflicting_broker_role_and_group_fails_closed(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $tenant = app(TenantService::class)->createTenant('Mapped Tenant', 'mapped-tenant', $admin);
        $service = app(GrandpaSsonTenantMappingService::class);

        $service->upsert(
            actor: $admin,
            brokerTenantId: 'gss_tenant_acme',
            tenant: $tenant,
            roleMappings: ['member' => 'viewer'],
            groupMappings: ['editors' => 'admin'],
        );

        $this->assertNull($service->resolve('gss_tenant_acme', 'member', ['editors']));
    }

    public function test_non_platform_admin_cannot_change_a_broker_tenant_mapping(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $operator = User::factory()->create(['is_platform_admin' => false]);
        $tenant = app(TenantService::class)->createTenant('Mapped Tenant', 'mapped-tenant', $admin);

        $this->expectException(AuthorizationException::class);

        app(GrandpaSsonTenantMappingService::class)->upsert(
            actor: $operator,
            brokerTenantId: 'gss_tenant_acme',
            tenant: $tenant,
            roleMappings: ['admin' => 'admin'],
            groupMappings: [],
        );
    }
}
