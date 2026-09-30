<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('ping', fn (): string => 'pong');
    Route::get('connection', fn (): string => DB::getDefaultConnection());
});
