<?php

declare(strict_types=1);

use Apps\Iam\Services\IamService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('users/find', fn (Request $request, IamService $iam) => $iam->findUser($request->integer('id')) ?? abort(404));

    Route::post('context/echo', fn () => ['trace_id' => Context::get('trace_id')]);
});
