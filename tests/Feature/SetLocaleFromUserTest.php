<?php

namespace Tests\Feature;

use App\Application\Tenancy\TenantService;
use App\Infrastructure\Persistence\Eloquent\User;
use App\Infrastructure\Persistence\Eloquent\UserPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetLocaleFromUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_validation_errors_render_in_the_users_saved_locale(): void
    {
        $user = User::factory()->create();
        $tenant = app(TenantService::class)->createTenant('Tenant A', 'tenant-a', $user);
        $environment = $tenant->environments()->first();
        UserPreference::query()->updateOrCreate(['user_id' => $user->id], ['locale' => 'pt_BR']);

        $response = $this->actingAs($user)->postJson(
            "/v1/tenants/{$tenant->public_id}/environments/{$environment->public_id}/incidents",
            [],
        );

        $response->assertStatus(422);
        $this->assertStringContainsString('é obrigatório', $response->json('errors.summary.0'));
    }

    public function test_validation_errors_default_to_english_without_a_saved_locale(): void
    {
        $user = User::factory()->create();
        $tenant = app(TenantService::class)->createTenant('Tenant A', 'tenant-a', $user);
        $environment = $tenant->environments()->first();

        $response = $this->actingAs($user)->postJson(
            "/v1/tenants/{$tenant->public_id}/environments/{$environment->public_id}/incidents",
            [],
        );

        $response->assertStatus(422);
        $this->assertStringContainsString('is required', $response->json('errors.summary.0'));
    }
}
