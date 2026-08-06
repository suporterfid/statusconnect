<?php

// Structure follows jotter app/Domain/Auth/Providers/GrandpaSSOnIdentityProvider.php,
// but this implementation uses the broker HTTP exchange rather than shared database access.

namespace App\Application\GrandpaSson;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class GrandpaSsonIdentityProvider
{
    /** @var list<string> */
    private const ALLOWED_PROVIDERS = ['google', 'microsoft', 'github'];

    public function loginUrl(string $provider, string $state): string
    {
        if (! in_array($provider, self::ALLOWED_PROVIDERS, true)) {
            throw new RuntimeException('Unsupported GrandpaSSOn login provider.');
        }

        $url = rtrim((string) config('grandpasson.login_url', ''), '/');
        $clientId = (string) config('grandpasson.rp_client_id', '');
        $redirectUri = (string) config('grandpasson.redirect_uri', '');

        if ($url === '' || $clientId === '' || $redirectUri === '') {
            throw new RuntimeException('GrandpaSSOn browser login is not configured.');
        }

        return $url.'/'.$provider.'?'.http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchange(string $code): ?GrandpaSsonBrowserIdentity
    {
        $url = (string) config('grandpasson.exchange_url', '');
        $clientId = (string) config('grandpasson.rp_client_id', '');
        $clientSecret = (string) config('grandpasson.rp_client_secret', '');
        $redirectUri = (string) config('grandpasson.redirect_uri', '');

        if ($url === '' || $clientId === '' || $clientSecret === '' || $redirectUri === '') {
            throw new RuntimeException('GrandpaSSOn exchange is not configured.');
        }

        $response = Http::asForm()->timeout(10)->post($url, [
            'code' => $code,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
        ]);

        if (! $response->successful()) {
            return null;
        }

        $subject = $response->json('subject');
        $email = is_array($subject) ? ($subject['email'] ?? null) : $response->json('email');
        $name = is_array($subject) ? ($subject['name'] ?? null) : $response->json('display_name');
        $tenant = $response->json('tenant');
        $groups = $response->json('groups', []);

        if (! is_string($email) || $email === '' || ! is_string($name) || $name === '' || ! is_array($tenant)
            || ! is_string($tenant['id'] ?? null) || $tenant['id'] === '' || ! is_string($tenant['role'] ?? null)
            || ! is_array($groups)) {
            return null;
        }

        $locale = $response->json('locale');
        if (! is_string($locale) || ! in_array($locale, ['en', 'pt_BR'], true)) {
            $locale = 'en';
        }

        return new GrandpaSsonBrowserIdentity(
            email: $email,
            name: $name,
            brokerTenantId: $tenant['id'],
            brokerRole: $tenant['role'],
            groups: array_values(array_filter($groups, 'is_string')),
            locale: $locale,
        );
    }
}
