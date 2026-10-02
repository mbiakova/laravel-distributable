<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get('hello', fn (): string => 'hello');
