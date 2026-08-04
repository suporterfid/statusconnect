<?php

// Adapted from taskconnect app/Application/GrandpaSson/CachedTokenClient.php.

namespace App\Application\GrandpaSson;

use Illuminate\Support\Facades\Cache;

final class CachedTokenClient implements TokenClientInterface
{
    public function __construct(
        private readonly TokenClientInterface $inner,
    ) {}

    public function clientCredentialsToken(string $scope): TokenResponse
    {
        $cacheKey = 'grandpasson:cc:'.hash('sha256', $scope);
        $cached = Cache::get($cacheKey);
        $skew = max(0, (int) config('grandpasson.token_refresh_skew_seconds', 60));

        if ($cached instanceof TokenResponse && now()->timestamp < $cached->expiresAtUnix - $skew) {
            return $cached;
        }

        $fresh = $this->inner->clientCredentialsToken($scope);
        $ttl = max(30, $fresh->expiresAtUnix - now()->timestamp - $skew);
        Cache::put($cacheKey, $fresh, now()->addSeconds($ttl));

        return $fresh;
    }
}
