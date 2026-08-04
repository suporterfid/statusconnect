<?php

namespace Tests\Feature\GrandpaSson;

use App\Application\GrandpaSson\CachedTokenClient;
use App\Application\GrandpaSson\HttpTokenClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CachedTokenClientTest extends TestCase
{
    public function test_requests_a_client_credentials_token_for_the_requested_status_scope(): void
    {
        $this->configureBroker();
        Http::fake([
            'https://broker.test/oauth/token' => Http::response([
                'access_token' => 'gpat_live_callback_token',
                'token_type' => 'Bearer',
                'expires_in' => 900,
                'scope' => 'status:callback',
            ]),
        ]);

        $token = app(HttpTokenClient::class)->clientCredentialsToken('status:callback');

        $this->assertSame('gpat_live_callback_token', $token->accessToken);
        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://broker.test/oauth/token'
                && $request->data() === [
                    'grant_type' => 'client_credentials',
                    'client_id' => 'statusconnect-service',
                    'client_secret' => 'service-secret',
                    'scope' => 'status:callback',
                ];
        });
    }

    public function test_caches_tokens_by_scope_and_refreshes_at_the_configured_skew(): void
    {
        $this->configureBroker();
        Cache::flush();
        Http::fake([
            'https://broker.test/oauth/token' => Http::sequence()
                ->push(['access_token' => 'gpat_live_first', 'expires_in' => 120, 'scope' => 'status:read'])
                ->push(['access_token' => 'gpat_live_second', 'expires_in' => 120, 'scope' => 'status:read']),
        ]);

        $client = new CachedTokenClient(app(HttpTokenClient::class));
        $this->assertSame('gpat_live_first', $client->clientCredentialsToken('status:read')->accessToken);
        $this->assertSame('gpat_live_first', $client->clientCredentialsToken('status:read')->accessToken);
        Http::assertSentCount(1);

        $this->travel(61)->seconds();

        $this->assertSame('gpat_live_second', $client->clientCredentialsToken('status:read')->accessToken);
        Http::assertSentCount(2);
    }

    private function configureBroker(): void
    {
        config([
            'grandpasson.token_url' => 'https://broker.test/oauth/token',
            'grandpasson.client_id' => 'statusconnect-service',
            'grandpasson.client_secret' => 'service-secret',
            'grandpasson.token_refresh_skew_seconds' => 60,
        ]);
    }
}
