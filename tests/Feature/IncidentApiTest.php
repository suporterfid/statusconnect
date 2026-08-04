<?php

namespace Tests\Feature;

use App\Application\ApiKeys\ApiKeyService;
use App\Application\Tenancy\TenantService;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidentApiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Environment $environment;

    private string $apiKeyPlain;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->tenant = app(TenantService::class)->createTenant('Acme', 'acme', $user);
        $this->environment = $this->tenant->environments()->sole();
        $this->apiKeyPlain = app(ApiKeyService::class)->create(
            tenant: $this->tenant,
            actor: $user,
            name: 'Incident API',
            permissions: ['*'],
            environment: $this->environment,
        )['plaintext'];
    }

    public function test_creates_a_manual_incident_and_posts_an_update_in_its_tenant_context(): void
    {
        $baseUrl = "/v1/tenants/{$this->tenant->public_id}/environments/{$this->environment->public_id}";
        $headers = ['Authorization' => 'Bearer '.$this->apiKeyPlain];

        $incident = $this->withHeaders($headers)->postJson("{$baseUrl}/incidents", [
            'summary' => 'Upstream dependency outage',
            'severity' => 'major',
        ])->assertCreated()->json('data');

        $this->assertTrue($incident['manual']);
        $this->assertNull($incident['monitor_id']);

        $this->withHeaders($headers)->postJson("{$baseUrl}/incidents/{$incident['public_id']}/updates", [
            'message' => 'Provider has acknowledged the outage.',
            'status' => 'investigating',
        ])->assertCreated()->assertJsonPath('data.message', 'Provider has acknowledged the outage.');

        $this->withHeaders($headers)->getJson("{$baseUrl}/incidents/{$incident['public_id']}")
            ->assertOk()
            ->assertJsonCount(1, 'data.updates');
    }
}
