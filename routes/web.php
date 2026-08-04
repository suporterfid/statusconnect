<?php

use App\Http\Controllers\Auth\GrandpaSsonLoginController;
use App\Http\Controllers\Public\StatusPageController;
use Illuminate\Support\Facades\Route;

Route::get('/auth/grandpasson/login/{provider}', [GrandpaSsonLoginController::class, 'redirect']);
Route::get('/auth/grandpasson/callback', [GrandpaSsonLoginController::class, 'callback']);

Route::middleware('public.status.throttle')->group(function () {
    Route::get('/status/{slug}.json', [StatusPageController::class, 'json'])
        ->where('slug', '[a-z0-9-]+');
    Route::get('/status/{slug}', [StatusPageController::class, 'show'])
        ->where('slug', '[a-z0-9-]+');
});

Route::get('/', function () {
    return response()->json([
        'name' => 'StatusConnect',
        'status' => 'healthy',
        'version' => '0.1.0',
    ]);
});
