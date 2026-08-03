<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/platform/health', function () {
        return response()->json([
            'status' => 'ok',
            'timestamp' => now()->toIso8601String(),
        ]);
    });

    Route::middleware(['auth.api_or_sanctum', 'tenant.context'])->group(function () {
        Route::get('/tenants/{tenantId}/environments/{environmentId}/ping', function () {
            return response()->json(['status' => 'pong']);
        });
    });
});
