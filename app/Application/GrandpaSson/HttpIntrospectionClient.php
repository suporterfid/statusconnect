<?php

// Adapted from taskconnect app/Application/GrandpaSson/HttpIntrospectionClient.php.
// GrandpaSSOn requires service-client credentials in the form body, not HTTP Basic auth.

namespace App\Application\GrandpaSson;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class HttpIntrospectionClient implements IntrospectionClientInterface
{
    public function introspect(string $token): IntrospectionResult
    {
        $cacheKey = 'grandpasson:introspection:'.hash('sha256', $token);
        $cached = Cache::get($cacheKey);

        if ($cached instanceof IntrospectionResult) {
            return $cached;
        }

        $result = $this->request($token);
        $ttl = $this->cacheTtl($result);

        if ($ttl > 0) {
            Cache::put($cacheKey, $result, now()->addSeconds($ttl));
        }

        return $result;
    }

    private function request(string $token): IntrospectionResult
    {
        $url = (string) config('grandpasson.introspect_url', '');
        $clientId = (string) config('grandpasson.client_id', '');
        $clientSecret = (string) config('grandpasson.client_secret', '');

        if ($url === '' || $clientId === '' || $clientSecret === '') {
            throw new RuntimeException('GrandpaSSOn introspection client is not configured.');
        }

        $response = Http::asForm()
            ->timeout(10)
            ->post($url, [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'token' => $token,
            ]);

        if (! $response->successful()) {
            return new IntrospectionResult(active: false);
        }

        $scope = $response->json('scope', '');
        $audience = $response->json('aud', []);
        $expiry = $response->json('exp');

        return new IntrospectionResult(
            active: (bool) $response->json('active', false),
            scopes: $this->stringList($scope),
            audiences: $this->stringList($audience),
            clientId: $this->nullableString($response->json('client_id')),
            subject: $this->nullableString($response->json('sub')),
            expiresAtUnix: is_numeric($expiry) ? (int) $expiry : null,
        );
    }

    private function cacheTtl(IntrospectionResult $result): int
    {
        $configured = max(0, (int) config('grandpasson.introspection_cache_seconds', 30));

        if ($result->expiresAtUnix === null) {
            return $configured;
        }

        return min($configured, max(0, $result->expiresAtUnix - now()->timestamp));
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            return array_values(array_filter(explode(' ', $value)));
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map('strval', $value));
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
