<?php

namespace Tests\Feature;

use App\Application\ApiKeys\ApiKeyService;
use App\Application\Tenancy\TenantService;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitorCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    private Environment $environment;

    private string $apiKeyPlain;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        /** @var TenantService $tenantService */
        $tenantService = app(TenantService::class);
        $this->tenant = $tenantService->createTenant('Acme Corp', 'acme-corp', $this->user);
        $this->environment = $this->tenant->environments()->firstOrFail();

        /** @var ApiKeyService $keyService */
        $keyService = app(ApiKeyService::class);
        $result = $keyService->create(
            tenant: $this->tenant,
            actor: $this->user,
            name: 'Test Key',
            permissions: ['*'],
            environment: $this->environment,
        );
        $this->apiKeyPlain = $result['plaintext'];
    }

    public function test_can_create_list_show_update_and_delete_monitor(): void
    {
        // 1. Create monitor
        $createResponse = $this->withHeader('Authorization', 'Bearer '.$this->apiKeyPlain)
            ->postJson("/v1/tenants/{$this->tenant->public_id}/environments/{$this->environment->public_id}/monitors", [
                'name' => 'API Health Check',
                'kind' => 'http',
                'target' => 'http://target:8095/status/200',
                'interval_seconds' => 60,
                'assertions' => [
                    [
                        'type' => 'status_code',
                        'operator' => 'between',
                        'expected_value' => '200..299',
                    ],
                    [
                        'type' => 'latency_ms',
                        'operator' => 'lt',
                        'expected_value' => '500',
                    ],
                ],
            ]);

        $createResponse->assertStatus(201);
        $createData = $createResponse->json('data');
        $this->assertNotNull($createData['public_id']);
        $this->assertEquals('API Health Check', $createData['name']);
        $this->assertCount(2, $createData['assertions']);

        $monitorId = $createData['public_id'];

        // 2. List monitors
        $listResponse = $this->withHeader('Authorization', 'Bearer '.$this->apiKeyPlain)
            ->getJson("/v1/tenants/{$this->tenant->public_id}/environments/{$this->environment->public_id}/monitors");

        if ($listResponse->status() !== 200) {
            dump('LIST FAILED:', $listResponse->status(), $listResponse->getContent());
        }
        $listResponse->assertStatus(200);
        $this->assertCount(1, $listResponse->json('data'));

        // 3. Show monitor
        $showResponse = $this->withHeader('Authorization', 'Bearer '.$this->apiKeyPlain)
            ->getJson("/v1/tenants/{$this->tenant->public_id}/environments/{$this->environment->public_id}/monitors/{$monitorId}");

        $showResponse->assertStatus(200);
        $this->assertEquals('API Health Check', $showResponse->json('data.name'));

        // 4. Update monitor
        $updateResponse = $this->withHeader('Authorization', 'Bearer '.$this->apiKeyPlain)
            ->putJson("/v1/tenants/{$this->tenant->public_id}/environments/{$this->environment->public_id}/monitors/{$monitorId}", [
                'name' => 'Updated API Health Check',
                'interval_seconds' => 120,
            ]);

        $updateResponse->assertStatus(200);
        $this->assertEquals('Updated API Health Check', $updateResponse->json('data.name'));
        $this->assertEquals(120, $updateResponse->json('data.interval_seconds'));

        // 5. Delete monitor
        $deleteResponse = $this->withHeader('Authorization', 'Bearer '.$this->apiKeyPlain)
            ->deleteJson("/v1/tenants/{$this->tenant->public_id}/environments/{$this->environment->public_id}/monitors/{$monitorId}");

        $deleteResponse->assertStatus(204);

        // Verify deleted
        $this->withHeader('Authorization', 'Bearer '.$this->apiKeyPlain)
            ->getJson("/v1/tenants/{$this->tenant->public_id}/environments/{$this->environment->public_id}/monitors/{$monitorId}")
            ->assertStatus(404);
    }

    public function test_rejects_ssrf_blocked_target_url(): void
    {
        $this->expectException(\App\Domain\Outbound\OutboundPolicyViolation::class);
        $monitorService = app(\App\Application\Monitoring\MonitorService::class);
        $monitorService->createMonitor($this->tenant, $this->environment, [
            'name' => 'Internal Service',
            'kind' => 'http',
            'target' => 'http://169.254.169.254/latest/meta-data/',
        ]);
    }
}
