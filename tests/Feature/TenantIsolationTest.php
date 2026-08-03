<?php

namespace Tests\Feature;

use App\Application\Tenancy\TenantService;
use App\Domain\Shared\TenantRole;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private TenantService $tenantService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantService = new TenantService();
    }

    public function test_user_can_access_own_tenant_and_environment(): void
    {
        $user = User::factory()->create();
        $tenant = $this->tenantService->createTenant('Tenant A', 'tenant-a', $user);
        $environment = $tenant->environments()->first();

        $response = $this->actingAs($user)
            ->getJson("/v1/tenants/{$tenant->public_id}/environments/{$environment->public_id}/ping");

        $response->assertStatus(200)
            ->assertJson(['status' => 'pong']);
    }

    public function test_cross_tenant_access_returns_404(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $tenantA = $this->tenantService->createTenant('Tenant A', 'tenant-a', $userA);
        $tenantB = $this->tenantService->createTenant('Tenant B', 'tenant-b', $userB);

        $envA = $tenantA->environments()->first();

        // User B attempts to access Tenant A -> 404
        $response = $this->actingAs($userB)
            ->getJson("/v1/tenants/{$tenantA->public_id}/environments/{$envA->public_id}/ping");

        $response->assertStatus(404);
    }

    public function test_mismatched_environment_returns_404(): void
    {
        $user = User::factory()->create();

        $tenantA = $this->tenantService->createTenant('Tenant A', 'tenant-a', $user);
        $tenantB = $this->tenantService->createTenant('Tenant B', 'tenant-b', $user);

        $envB = $tenantB->environments()->first();

        // User attempts to query Tenant A with Environment B's ID -> 404
        $response = $this->actingAs($user)
            ->getJson("/v1/tenants/{$tenantA->public_id}/environments/{$envB->public_id}/ping");

        $response->assertStatus(404);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $user = User::factory()->create();
        $tenant = $this->tenantService->createTenant('Tenant A', 'tenant-a', $user);
        $environment = $tenant->environments()->first();

        $response = $this->getJson("/v1/tenants/{$tenant->public_id}/environments/{$environment->public_id}/ping");

        $response->assertStatus(401);
    }
}
