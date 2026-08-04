<?php

namespace Tests\Feature\GrandpaSson;

use App\Application\Tenancy\TenantService;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantMappingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_can_create_a_local_broker_tenant_mapping(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $tenant = app(TenantService::class)->createTenant('Mapped Tenant', 'mapped-tenant', $admin);

        $this->actingAs($admin)
            ->postJson('/v1/platform/grandpasson/tenant-mappings', [
                'broker_tenant_id' => 'gss_tenant_acme',
                'tenant_public_id' => $tenant->public_id,
                'role_mappings' => ['owner' => 'owner', 'member' => 'viewer'],
                'group_mappings' => ['editors' => 'admin'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.broker_tenant_id', 'gss_tenant_acme')
            ->assertJsonPath('data.tenant_public_id', $tenant->public_id);

        $this->assertDatabaseHas('grandpasson_tenant_mappings', [
            'broker_tenant_id' => 'gss_tenant_acme',
            'tenant_id' => $tenant->id,
        ]);
    }

    public function test_non_platform_admin_cannot_create_a_mapping(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $operator = User::factory()->create(['is_platform_admin' => false]);
        $tenant = app(TenantService::class)->createTenant('Mapped Tenant', 'mapped-tenant', $admin);

        $this->actingAs($operator)
            ->postJson('/v1/platform/grandpasson/tenant-mappings', [
                'broker_tenant_id' => 'gss_tenant_acme',
                'tenant_public_id' => $tenant->public_id,
                'role_mappings' => ['owner' => 'owner'],
                'group_mappings' => [],
            ])
            ->assertForbidden();
    }
}
