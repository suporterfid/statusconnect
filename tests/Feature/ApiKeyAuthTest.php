<?php

namespace Tests\Feature;

use App\Application\ApiKeys\ApiKeyService;
use App\Application\Tenancy\TenantService;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiKeyAuthTest extends TestCase
{
    use RefreshDatabase;

    private TenantService $tenantService;
    private ApiKeyService $apiKeyService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantService = new TenantService();
        $this->apiKeyService = new ApiKeyService();
    }

    public function test_api_key_creation_and_bearer_auth(): void
    {
        $user = User::factory()->create();
        $tenant = $this->tenantService->createTenant('Tenant A', 'tenant-a', $user);
        $environment = $tenant->environments()->first();

        $keyData = $this->apiKeyService->create(
            tenant: $tenant,
            actor: $user,
            name: 'Test Key',
            permissions: ['*'],
            environment: $environment
        );

        $plaintext = $keyData['plaintext'];
        $apiKey = $keyData['api_key'];

        $this->assertStringStartsWith('sc_', $plaintext);
        $this->assertEquals(hash('sha256', $plaintext), $apiKey->key_hash);

        // Authenticated request via Bearer header
        $response = $this->withHeader('Authorization', "Bearer {$plaintext}")
            ->getJson("/v1/tenants/{$tenant->public_id}/environments/{$environment->public_id}/ping");

        $response->assertStatus(200)
            ->assertJson(['status' => 'pong']);
    }

    public function test_invalid_api_key_returns_401(): void
    {
        $user = User::factory()->create();
        $tenant = $this->tenantService->createTenant('Tenant A', 'tenant-a', $user);
        $environment = $tenant->environments()->first();

        $response = $this->withHeader('Authorization', 'Bearer sc_invalidtoken123456789012345678901234567890')
            ->getJson("/v1/tenants/{$tenant->public_id}/environments/{$environment->public_id}/ping");

        $response->assertStatus(401);
    }

    public function test_revoked_api_key_returns_401(): void
    {
        $user = User::factory()->create();
        $tenant = $this->tenantService->createTenant('Tenant A', 'tenant-a', $user);
        $environment = $tenant->environments()->first();

        $keyData = $this->apiKeyService->create(
            tenant: $tenant,
            actor: $user,
            name: 'Revoked Key',
            permissions: ['*'],
            environment: $environment
        );

        $this->apiKeyService->revoke($keyData['api_key']);

        $response = $this->withHeader('Authorization', "Bearer {$keyData['plaintext']}")
            ->getJson("/v1/tenants/{$tenant->public_id}/environments/{$environment->public_id}/ping");

        $response->assertStatus(401);
    }

    public function test_api_key_environment_mismatch_returns_404(): void
    {
        $user = User::factory()->create();
        $tenant = $this->tenantService->createTenant('Tenant A', 'tenant-a', $user);

        $envA = $tenant->environments()->first();
        $envB = $tenant->environments()->create([
            'name' => 'Staging',
            'slug' => 'staging',
        ]);

        $keyData = $this->apiKeyService->create(
            tenant: $tenant,
            actor: $user,
            name: 'Env A Key',
            permissions: ['*'],
            environment: $envA
        );

        // Accessing Env B with Env A's API Key returns 404
        $response = $this->withHeader('Authorization', "Bearer {$keyData['plaintext']}")
            ->getJson("/v1/tenants/{$tenant->public_id}/environments/{$envB->public_id}/ping");

        $response->assertStatus(404);
    }
}
