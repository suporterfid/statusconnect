<?php

namespace Tests\Feature;

use App\Application\Tenancy\TenantService;
use App\Infrastructure\Persistence\Eloquent\User;
use App\Infrastructure\Persistence\Eloquent\UserPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalizedMessagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_idempotency_key_message_is_localized(): void
    {
        $user = User::factory()->create();
        $tenant = app(TenantService::class)->createTenant('Tenant A', 'tenant-a', $user);
        $environment = $tenant->environments()->first();
        UserPreference::query()->updateOrCreate(['user_id' => $user->id], ['locale' => 'pt_BR']);

        $response = $this->actingAs($user)->postJson(
            "/v1/tenants/{$tenant->public_id}/environments/{$environment->public_id}/incidents",
            ['summary' => 'Outage', 'severity' => 'major'],
        );

        $response->assertStatus(422);
        $this->assertSame('Um cabeçalho Idempotency-Key válido é obrigatório.', $response->json('message'));
    }

    public function test_invalid_broker_role_mapping_message_is_localized(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $tenant = app(TenantService::class)->createTenant('Mapped Tenant', 'mapped-tenant', $admin);
        UserPreference::query()->updateOrCreate(['user_id' => $admin->id], ['locale' => 'pt_BR']);

        $response = $this->actingAs($admin)->postJson('/v1/platform/grandpasson/tenant-mappings', [
            'broker_tenant_id' => 'gss_tenant_acme',
            'tenant_public_id' => $tenant->public_id,
            'role_mappings' => ['owner' => 'owner', 'bogus' => 'viewer'],
            'group_mappings' => ['editors' => 'admin'],
        ]);

        $response->assertStatus(422);
        $this->assertSame('Mapeamentos de papel do broker só podem ser owner, admin ou member.', $response->json('message'));
    }
}
