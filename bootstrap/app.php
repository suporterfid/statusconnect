<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->statefulApi();

        $middleware->alias([
            'auth.api_or_sanctum' => \App\Http\Middleware\AuthenticateApiKeyOrSanctum::class,
            'tenant.context' => \App\Http\Middleware\ResolveTenantEnvironment::class,
            'grandpasson.workspace_aud' => \App\Http\Middleware\EnforceGrandpaSsonWorkspaceAud::class,
            'public.status.throttle' => \App\Http\Middleware\EnforcePublicStatusPageRateLimit::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
