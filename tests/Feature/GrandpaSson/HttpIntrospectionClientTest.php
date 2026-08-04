<?php

namespace Tests\Feature\GrandpaSson;

use App\Application\GrandpaSson\HttpIntrospectionClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HttpIntrospectionClientTest extends TestCase
{
    public function test_posts_credentials_and_token_in_the_form_body(): void
    {
        config([
            'grandpasson.introspect_url' => 'https://broker.test/oauth/introspect',
            'grandpasson.client_id' => 'statusconnect-service',
            'grandpasson.client_secret' => 'service-secret',
        ]);
        Http::fake([
            'https://broker.test/oauth/introspect' => Http::response([
                'active' => true,
                'scope' => 'status:read',
                'aud' => 'env_01HTEST',
                'exp' => now()->addMinutes(5)->timestamp,
            ]),
        ]);

        $result = app(HttpIntrospectionClient::class)->introspect('gpat_live_opaque');

        $this->assertTrue($result->active);
        $this->assertSame(['status:read'], $result->scopes);
        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://broker.test/oauth/introspect'
                && $request->data() === [
                    'client_id' => 'statusconnect-service',
                    'client_secret' => 'service-secret',
                    'token' => 'gpat_live_opaque',
                ]
                && $request->hasHeader('Content-Type', 'application/x-www-form-urlencoded');
        });
    }

    public function test_caches_an_active_result_no_longer_than_the_token_expiry(): void
    {
        config([
            'grandpasson.introspect_url' => 'https://broker.test/oauth/introspect',
            'grandpasson.client_id' => 'statusconnect-service',
            'grandpasson.client_secret' => 'service-secret',
            'grandpasson.introspection_cache_seconds' => 30,
        ]);
        Http::fake([
            'https://broker.test/oauth/introspect' => Http::response([
                'active' => true,
                'scope' => 'status:read',
                'aud' => ['env_01HTEST'],
                'exp' => now()->addSeconds(5)->timestamp,
            ]),
        ]);

        $client = app(HttpIntrospectionClient::class);
        $client->introspect('gpat_live_short_lived');
        $client->introspect('gpat_live_short_lived');
        Http::assertSentCount(1);

        $this->travel(6)->seconds();
        $client->introspect('gpat_live_short_lived');

        Http::assertSentCount(2);
    }
}
