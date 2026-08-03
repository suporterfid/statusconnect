<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('statusconnect:version', function () {
    $this->info('StatusConnect v0.1.0 scaffold');
})->purpose('Display application version');
