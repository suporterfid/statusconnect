<?php

use App\Http\Controllers\Auth\GrandpaSsonLoginController;
use Illuminate\Support\Facades\Route;

Route::get('/auth/grandpasson/login/{provider}', [GrandpaSsonLoginController::class, 'redirect']);
Route::get('/auth/grandpasson/callback', [GrandpaSsonLoginController::class, 'callback']);

Route::get('/', function () {
    return response()->json([
        'name' => 'StatusConnect',
        'status' => 'healthy',
        'version' => '0.1.0',
    ]);
});
