<?php

namespace Tests\Feature\GrandpaSson;

use App\Application\GrandpaSson\GrandpaSsonTenantMappingService;
use App\Application\Tenancy\TenantService;
use App\Infrastructure\Persistence\Eloquent\TenantMembership;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BrowserLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_redirect_stores_state_and_targets_the_selected_broker_provider(): void
    {
        $this->configureBroker();

        $response = $this->get('/auth/grandpasson/login/github');

        $response->assertRedirect();
        $response->assertSessionHas('grandpasson.oauth_state');
        $this->assertStringStartsWith('https://broker.test/login/github?', $response->headers->get('Location'));
    }

    public function test_callback_redeems_the_code_and_grants_the_explicitly_mapped_membership(): void
    {
        $this->configureBroker();
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $tenant = app(TenantService::class)->createTenant('Mapped Tenant', 'mapped-tenant', $admin);
        app(GrandpaSsonTenantMappingService::class)->upsert(
            actor: $admin,
            brokerTenantId: 'gss_tenant_acme',
            tenant: $tenant,
            roleMappings: ['admin' => 'admin'],
            groupMappings: ['editors' => 'admin'],
        );
        Http::fake([
            'https://broker.test/session/exchange' => Http::response([
                'subject' => ['id' => 'gss_subject_1', 'email' => 'operator@example.test', 'name' => 'Operator'],
                'tenant' => ['id' => 'gss_tenant_acme', 'role' => 'admin'],
                'groups' => ['editors'],
            ]),
        ]);

        $login = $this->get('/auth/grandpasson/login/github');
        parse_str((string) parse_url($login->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->get('/auth/grandpasson/callback?code=one-time-code&state='.$query['state'])
            ->assertRedirect('/');

        $user = User::query()->where('email', 'operator@example.test')->sole();
        $membership = TenantMembership::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->sole();
        $this->assertSame('admin', $membership->role);
        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://broker.test/session/exchange'
                && $request->data() === [
                    'code' => 'one-time-code',
                    'client_id' => 'statusconnect-browser',
                    'client_secret' => 'browser-secret',
                    'redirect_uri' => 'https://status.test/auth/grandpasson/callback',
                ];
        });
    }

    public function test_callback_syncs_the_locale_claim_into_user_preferences(): void
    {
        $this->configureBroker();
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $tenant = app(TenantService::class)->createTenant('Mapped Tenant', 'mapped-tenant', $admin);
        app(GrandpaSsonTenantMappingService::class)->upsert(
            actor: $admin,
            brokerTenantId: 'gss_tenant_acme',
            tenant: $tenant,
            roleMappings: ['admin' => 'admin'],
            groupMappings: ['editors' => 'admin'],
        );
        Http::fake([
            'https://broker.test/session/exchange' => Http::response([
                'subject' => ['id' => 'gss_subject_1', 'email' => 'locale-operator@example.test', 'name' => 'Operator'],
                'tenant' => ['id' => 'gss_tenant_acme', 'role' => 'admin'],
                'groups' => ['editors'],
                'locale' => 'pt_BR',
            ]),
        ]);

        $login = $this->get('/auth/grandpasson/login/github');
        parse_str((string) parse_url($login->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->get('/auth/grandpasson/callback?code=one-time-code&state='.$query['state']);

        $user = User::query()->where('email', 'locale-operator@example.test')->sole();
        $this->assertSame('pt_BR', \App\Infrastructure\Persistence\Eloquent\UserPreference::query()->where('user_id', $user->id)->value('locale'));
    }

    public function test_callback_defaults_locale_to_english_when_the_claim_is_missing(): void
    {
        $this->configureBroker();
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $tenant = app(TenantService::class)->createTenant('Mapped Tenant', 'mapped-tenant', $admin);
        app(GrandpaSsonTenantMappingService::class)->upsert(
            actor: $admin,
            brokerTenantId: 'gss_tenant_acme',
            tenant: $tenant,
            roleMappings: ['admin' => 'admin'],
            groupMappings: ['editors' => 'admin'],
        );
        Http::fake([
            'https://broker.test/session/exchange' => Http::response([
                'subject' => ['id' => 'gss_subject_1', 'email' => 'no-locale-operator@example.test', 'name' => 'Operator'],
                'tenant' => ['id' => 'gss_tenant_acme', 'role' => 'admin'],
                'groups' => ['editors'],
            ]),
        ]);

        $login = $this->get('/auth/grandpasson/login/github');
        parse_str((string) parse_url($login->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->get('/auth/grandpasson/callback?code=one-time-code&state='.$query['state']);

        $user = User::query()->where('email', 'no-locale-operator@example.test')->sole();
        $this->assertSame('en', \App\Infrastructure\Persistence\Eloquent\UserPreference::query()->where('user_id', $user->id)->value('locale'));
    }

    public function test_second_login_updates_the_existing_preference_row(): void
    {
        $this->configureBroker();
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $tenant = app(TenantService::class)->createTenant('Mapped Tenant', 'mapped-tenant', $admin);
        app(GrandpaSsonTenantMappingService::class)->upsert(
            actor: $admin,
            brokerTenantId: 'gss_tenant_acme',
            tenant: $tenant,
            roleMappings: ['admin' => 'admin'],
            groupMappings: ['editors' => 'admin'],
        );

        Http::fakeSequence('https://broker.test/session/exchange')
            ->push([
                'subject' => ['id' => 'gss_subject_1', 'email' => 'repeat-operator@example.test', 'name' => 'Operator'],
                'tenant' => ['id' => 'gss_tenant_acme', 'role' => 'admin'],
                'groups' => ['editors'],
                'locale' => 'pt_BR',
            ])
            ->push([
                'subject' => ['id' => 'gss_subject_1', 'email' => 'repeat-operator@example.test', 'name' => 'Operator'],
                'tenant' => ['id' => 'gss_tenant_acme', 'role' => 'admin'],
                'groups' => ['editors'],
                'locale' => 'en',
            ]);
        $login = $this->get('/auth/grandpasson/login/github');
        parse_str((string) parse_url($login->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->get('/auth/grandpasson/callback?code=one-time-code&state='.$query['state']);

        $user = User::query()->where('email', 'repeat-operator@example.test')->sole();
        $this->assertSame(1, \App\Infrastructure\Persistence\Eloquent\UserPreference::query()->where('user_id', $user->id)->count());

        $login2 = $this->get('/auth/grandpasson/login/github');
        parse_str((string) parse_url($login2->headers->get('Location'), PHP_URL_QUERY), $query2);
        $this->get('/auth/grandpasson/callback?code=another-code&state='.$query2['state']);

        $this->assertSame(1, \App\Infrastructure\Persistence\Eloquent\UserPreference::query()->where('user_id', $user->id)->count());
        $this->assertSame('en', \App\Infrastructure\Persistence\Eloquent\UserPreference::query()->where('user_id', $user->id)->value('locale'));
    }

    public function test_state_mismatch_fails_closed_without_calling_the_broker(): void
    {
        $this->configureBroker();
        Http::fake();

        $this->withSession(['grandpasson.oauth_state' => 'expected-state'])
            ->get('/auth/grandpasson/callback?code=one-time-code&state=wrong-state')
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_rejected_or_expired_code_fails_closed(): void
    {
        $this->configureBroker();
        Http::fake([
            'https://broker.test/session/exchange' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $this->withSession(['grandpasson.oauth_state' => 'expected-state'])
            ->get('/auth/grandpasson/callback?code=expired-code&state=expected-state')
            ->assertUnauthorized();

        $this->assertSame(0, User::query()->where('email', 'operator@example.test')->count());
    }

    private function configureBroker(): void
    {
        config([
            'grandpasson.inbound_enabled' => true,
            'grandpasson.login_url' => 'https://broker.test/login',
            'grandpasson.exchange_url' => 'https://broker.test/session/exchange',
            'grandpasson.rp_client_id' => 'statusconnect-browser',
            'grandpasson.rp_client_secret' => 'browser-secret',
            'grandpasson.redirect_uri' => 'https://status.test/auth/grandpasson/callback',
        ]);
    }
}
