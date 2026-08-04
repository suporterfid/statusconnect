<?php

// Adapted from taskconnect app/Application/GrandpaSson/HttpTokenClient.php.

namespace App\Application\GrandpaSson;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class HttpTokenClient implements TokenClientInterface
{
    public function clientCredentialsToken(string $scope): TokenResponse
    {
        $url = (string) config('grandpasson.token_url', '');
        $clientId = (string) config('grandpasson.client_id', '');
        $clientSecret = (string) config('grandpasson.client_secret', '');

        if ($url === '' || $clientId === '' || $clientSecret === '') {
            throw new RuntimeException('GrandpaSSOn token client is not configured.');
        }

        $response = Http::asForm()->timeout(10)->post($url, [
            'grant_type' => 'client_credentials',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'scope' => $scope,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('GrandpaSSOn token request failed: HTTP '.$response->status());
        }

        $accessToken = (string) $response->json('access_token', '');
        if ($accessToken === '') {
            throw new RuntimeException('GrandpaSSOn token response missing access_token.');
        }

        return new TokenResponse(
            accessToken: $accessToken,
            expiresAtUnix: now()->timestamp + max(60, (int) $response->json('expires_in', 900)),
            tokenType: (string) $response->json('token_type', 'Bearer'),
            scope: $scope,
        );
    }
}
