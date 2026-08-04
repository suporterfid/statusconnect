<?php

namespace Tests\Feature\GrandpaSson;

use App\Application\GrandpaSson\IntrospectionClientInterface;
use App\Application\GrandpaSson\IntrospectionResult;
use App\Application\Tenancy\TenantService;
use App\Infrastructure\Persistence\Eloquent\AuditLog;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeGrandpaSsonIntrospectionClient;
use Tests\TestCase;

class InboundAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_read_token_with_raw_environment_audience_can_read(): void
    {
        [$tenant, $environment] = $this->tenantAndEnvironment();
        $this->enableInbound((new FakeGrandpaSsonIntrospectionClient)->withToken('gpat_live_raw', new IntrospectionResult(
            active: true,
            scopes: ['status:read'],
            audiences: [$environment->public_id],
        )));

        $this->withHeader('Authorization', 'Bearer gpat_live_raw')
            ->getJson("/v1/tenants/{$tenant->public_id}/environments/{$environment->public_id}/ping")
            ->assertOk()
            ->assertJson(['status' => 'pong']);
    }

    public function test_active_read_token_with_prefixed_environment_audience_can_read(): void
    {
        [$tenant, $environment] = $this->tenantAndEnvironment();
        $this->enableInbound((new FakeGrandpaSsonIntrospectionClient)->withToken('gpat_live_prefixed', new IntrospectionResult(
            active: true,
            scopes: ['status:read'],
            audiences: ['workspace/'.$environment->public_id],
        )));

        $this->withHeader('Authorization', 'Bearer gpat_live_prefixed')
            ->getJson("/v1/tenants/{$tenant->public_id}/environments/{$environment->public_id}/ping")
            ->assertOk();
    }

    public function test_read_scope_cannot_perform_a_write(): void
    {
        [$tenant, $environment] = $this->tenantAndEnvironment();
        $this->enableInbound((new FakeGrandpaSsonIntrospectionClient)->withToken('gpat_live_read_only', new IntrospectionResult(
            active: true,
            scopes: ['status:read'],
            audiences: [$environment->public_id],
        )));

        $this->withHeader('Authorization', 'Bearer gpat_live_read_only')
            ->withHeader('Idempotency-Key', 'gss-read-cannot-write')
            ->postJson("/v1/tenants/{$tenant->public_id}/environments/{$environment->public_id}/incidents", [
                'summary' => 'Read token must not create incidents',
                'severity' => 'minor',
            ])
            ->assertForbidden();
    }

    public function test_wrong_audience_is_rejected_and_audit_does_not_contain_the_bearer_token(): void
    {
        [$tenant, $environment] = $this->tenantAndEnvironment();
        $token = 'gpat_live_wrong_audience_secret';
        $this->enableInbound((new FakeGrandpaSsonIntrospectionClient)->withToken($token, new IntrospectionResult(
            active: true,
            scopes: ['status:read'],
            audiences: ['env_01HOTHER'],
        )));

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/v1/tenants/{$tenant->public_id}/environments/{$environment->public_id}/ping")
            ->assertForbidden();

        $audit = AuditLog::query()->where('action', 'grandpasson.workspace_denied')->sole();
        $this->assertStringNotContainsString($token, json_encode($audit->summary_json, JSON_THROW_ON_ERROR));
        $this->assertSame(hash('sha256', $token), $audit->summary_json['token_fingerprint']);
    }

    private function enableInbound(FakeGrandpaSsonIntrospectionClient $fake): void
    {
        config([
            'grandpasson.inbound_enabled' => true,
            'grandpasson.read_scope' => 'status:read',
            'grandpasson.write_scope' => 'status:write',
        ]);
        $this->app->instance(IntrospectionClientInterface::class, $fake);
    }

    /** @return array{0: \App\Infrastructure\Persistence\Eloquent\Tenant, 1: \App\Infrastructure\Persistence\Eloquent\Environment} */
    private function tenantAndEnvironment(): array
    {
        $tenant = app(TenantService::class)->createTenant('GrandpaSSOn Tenant', 'grandpasson-tenant', User::factory()->create());

        return [$tenant, $tenant->environments()->sole()];
    }
}
