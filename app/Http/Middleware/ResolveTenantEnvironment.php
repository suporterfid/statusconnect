<?php

// Ported / mirrored from TaskConnect: app/Http/Middleware/ResolveTenantEnvironment.php

namespace App\Http\Middleware;

use App\Infrastructure\Persistence\Eloquent\ApiKey;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenantEnvironment
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        /** @var ApiKey|null $apiKey */
        $apiKey = $request->attributes->get('api_key');

        if ($user === null && $apiKey === null) {
            abort(401);
        }

        $tenantPublicId = $request->route('tenantId');

        if ($tenantPublicId === null) {
            return $next($request);
        }

        $tenant = Tenant::query()
            ->where('public_id', $tenantPublicId)
            ->first();

        if ($tenant === null) {
            abort(404);
        }

        if ($apiKey !== null) {
            if ($apiKey->tenant_id !== $tenant->id) {
                abort(404);
            }
        } elseif (! $this->canAccessTenant($user, $tenant)) {
            abort(404);
        }

        $request->attributes->set('tenant', $tenant);

        $environmentPublicId = $request->route('environmentId');

        if ($environmentPublicId === null) {
            return $next($request);
        }

        $environment = Environment::query()
            ->where('public_id', $environmentPublicId)
            ->where('tenant_id', $tenant->id)
            ->first();

        if ($environment === null) {
            abort(404);
        }

        if ($apiKey !== null && $apiKey->environment_id !== null && $apiKey->environment_id !== $environment->id) {
            abort(404);
        }

        $request->attributes->set('environment', $environment);

        return $next($request);
    }

    private function canAccessTenant($user, Tenant $tenant): bool
    {
        if (method_exists($user, 'isPlatformAdmin') && $user->isPlatformAdmin()) {
            return true;
        }

        if (method_exists($user, 'memberships')) {
            return $user->memberships()
                ->where('tenant_id', $tenant->id)
                ->exists();
        }

        return false;
    }
}
