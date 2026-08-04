<?php

namespace Tests\Feature;

use App\Application\ApiKeys\ApiKeyService;
use App\Application\Tenancy\TenantService;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Incident;
use App\Infrastructure\Persistence\Eloquent\IncidentUpdate;
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
        $headers = [
            'Authorization' => 'Bearer '.$this->apiKeyPlain,
            'Idempotency-Key' => 'manual-incident-001',
        ];

        $incident = $this->withHeaders($headers)->postJson("{$baseUrl}/incidents", [
            'summary' => 'Upstream dependency outage',
            'severity' => 'major',
        ])->assertCreated()->json('data');

        $this->assertTrue($incident['manual']);
        $this->assertArrayNotHasKey('id', $incident);
        $this->assertArrayNotHasKey('tenant_id', $incident);
        $this->assertArrayNotHasKey('environment_id', $incident);
        $this->assertArrayNotHasKey('monitor_id', $incident);

        $updateHeaders = array_replace($headers, ['Idempotency-Key' => 'incident-update-001']);
        $update = $this->withHeaders($updateHeaders)->postJson("{$baseUrl}/incidents/{$incident['public_id']}/updates", [
            'message' => 'Provider has acknowledged the outage.',
            'status' => 'investigating',
        ])->assertCreated()->assertJsonPath('data.message', 'Provider has acknowledged the outage.')->json('data');
        $this->assertArrayNotHasKey('id', $update);
        $this->assertArrayNotHasKey('incident_id', $update);

        $this->withHeaders($headers)->getJson("{$baseUrl}/incidents/{$incident['public_id']}")
            ->assertOk()
            ->assertJsonCount(1, 'data.updates');
    }

    public function test_replays_a_write_with_the_same_idempotency_key_without_duplicate_records(): void
    {
        $baseUrl = "/v1/tenants/{$this->tenant->public_id}/environments/{$this->environment->public_id}";
        $headers = [
            'Authorization' => 'Bearer '.$this->apiKeyPlain,
            'Idempotency-Key' => 'manual-incident-replay',
        ];
        $payload = ['summary' => 'Cached write', 'severity' => 'minor'];

        $first = $this->withHeaders($headers)->postJson("{$baseUrl}/incidents", $payload)->assertCreated();
        $second = $this->withHeaders($headers)->postJson("{$baseUrl}/incidents", $payload)->assertCreated();

        $this->assertSame($first->json('data.public_id'), $second->json('data.public_id'));
        $this->assertSame(1, Incident::query()->count());
    }

    public function test_operator_can_resolve_a_manual_incident_idempotently(): void
    {
        $baseUrl = "/v1/tenants/{$this->tenant->public_id}/environments/{$this->environment->public_id}";
        $incident = Incident::query()->create([
            'tenant_id' => $this->tenant->id,
            'environment_id' => $this->environment->id,
            'manual' => true,
            'resolved_flag' => false,
            'started_at' => '2026-08-04 10:00:00',
            'severity' => 'major',
            'summary' => 'Manual outage',
        ]);

        $this->withHeaders([
            'Authorization' => 'Bearer '.$this->apiKeyPlain,
            'Idempotency-Key' => 'manual-resolve-001',
        ])->postJson("{$baseUrl}/incidents/{$incident->public_id}/resolve")
            ->assertOk()
            ->assertJsonPath('data.resolved', true);

        $resolved = $incident->fresh();
        $this->assertNotNull($resolved->resolved_at);
        $this->assertNull($resolved->resolved_flag);
    }

    public function test_idempotency_key_is_scoped_to_the_environment(): void
    {
        $secondEnvironment = Environment::query()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Staging',
            'slug' => 'staging',
        ]);
        $unscopedKey = app(ApiKeyService::class)->create(
            tenant: $this->tenant,
            actor: User::query()->firstOrFail(),
            name: 'Tenant incident API',
            permissions: ['*'],
        )['plaintext'];
        $payload = ['summary' => 'Independent write', 'severity' => 'minor'];
        $key = 'same-key-different-environments';

        $first = $this->withHeaders([
            'Authorization' => 'Bearer '.$unscopedKey,
            'Idempotency-Key' => $key,
        ])->postJson("/v1/tenants/{$this->tenant->public_id}/environments/{$this->environment->public_id}/incidents", $payload)
            ->assertCreated();
        $second = $this->withHeaders([
            'Authorization' => 'Bearer '.$unscopedKey,
            'Idempotency-Key' => $key,
        ])->postJson("/v1/tenants/{$this->tenant->public_id}/environments/{$secondEnvironment->public_id}/incidents", $payload)
            ->assertCreated();

        $this->assertNotSame($first->json('data.public_id'), $second->json('data.public_id'));
        $this->assertSame(2, Incident::query()->count());
    }
}
