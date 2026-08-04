<?php

use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\IncidentController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\MonitorController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::middleware(['web', 'throttle:10,1'])->post('/auth/login', LoginController::class);

    Route::middleware(['web', 'auth.api_or_sanctum'])->group(function () {
        Route::post('/auth/logout', LogoutController::class);
        Route::get('/me', MeController::class);
    });

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

        Route::prefix('/tenants/{tenantId}/environments/{environmentId}')->group(function () {
            Route::get('/incidents', [IncidentController::class, 'index']);
            Route::post('/incidents', [IncidentController::class, 'store']);
            Route::get('/incidents/{incidentId}', [IncidentController::class, 'show']);
            Route::post('/incidents/{incidentId}/updates', [IncidentController::class, 'storeUpdate']);
            Route::post('/incidents/{incidentId}/resolve', [IncidentController::class, 'resolve']);
            Route::get('/monitors', [MonitorController::class, 'index']);
            Route::post('/monitors', [MonitorController::class, 'store']);
            Route::get('/monitors/{monitorId}', [MonitorController::class, 'show']);
            Route::put('/monitors/{monitorId}', [MonitorController::class, 'update']);
            Route::delete('/monitors/{monitorId}', [MonitorController::class, 'destroy']);
        });
    });
});
