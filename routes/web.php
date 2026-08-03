<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'name' => 'StatusConnect',
        'status' => 'healthy',
        'version' => '0.1.0',
    ]);
});
