<?php

// Adapted from taskconnect app/Http/Middleware/EnforceGrandpaSsonWorkspaceAud.php.

namespace App\Http\Middleware;

use App\Auth\GrandpaSsonActor;
use App\Infrastructure\Persistence\Eloquent\AuditLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceGrandpaSsonWorkspaceAud
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('grandpasson.inbound_enabled', false)) {
            return $next($request);
        }

        $user = $request->user();
        if (! $user instanceof GrandpaSsonActor) {
            return $next($request);
        }

        $environment = $request->attributes->get('environment');
        $environmentPublicId = is_object($environment) ? (string) ($environment->public_id ?? '') : '';
        $requiredScope = $this->requiredScope($request);
        $result = $user->introspection;

        if ($result->active && $environmentPublicId !== '' && $result->hasScope($requiredScope) && $result->audienceIncludes($environmentPublicId)) {
            return $next($request);
        }

        $tenant = $request->attributes->get('tenant');
        AuditLog::query()->create([
            'tenant_id' => is_object($tenant) ? $tenant->id : null,
            'environment_id' => is_object($environment) ? $environment->id : null,
            'action' => 'grandpasson.workspace_denied',
            'resource_type' => 'environment',
            'resource_id' => $environmentPublicId !== '' ? $environmentPublicId : null,
            'summary_json' => [
                'reason' => 'aud_or_scope_mismatch',
                'required_scope' => $requiredScope,
                'scopes' => $result->scopes,
                'audiences' => $result->audiences,
                'token_fingerprint' => $user->tokenFingerprint,
            ],
        ]);

        abort(403, 'GrandpaSSOn token is not authorized for this environment.');
    }

    private function requiredScope(Request $request): string
    {
        return $request->isMethodSafe()
            ? (string) config('grandpasson.read_scope', 'status:read')
            : (string) config('grandpasson.write_scope', 'status:write');
    }
}
