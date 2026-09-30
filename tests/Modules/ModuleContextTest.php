<?php

declare(strict_types=1);

use Apps\Iam\Jobs\RecordConnection;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modulith\Services\Modules\ModuleContext;
use Modulith\Tests\Support\ModuleAppTestCase;

// As a real `php artisan` run does, so CommandStarting fires.
uses(ModuleAppTestCase::class, WithConsoleEvents::class);

it('runs a module route on the module connection', function () {
    $this->get('/iam/api/v1/connection')->assertOk()->assertSee('iam');
});

it('runs a module command on the module connection', function () {
    $this->artisan('iam:connection')->expectsOutput('iam')->assertSuccessful();
});

it('runs a module job on the module connection', function () {
    RecordConnection::$seen = null;

    dispatch(new RecordConnection);

    expect(RecordConnection::$seen)->toBe('iam');
});

it('gives the application its own connection back outside any module', function () {
    $default = DB::getDefaultConnection();
    $context = app(ModuleContext::class);

    $context->switchToModuleOf(RecordConnection::class);
    expect(DB::getDefaultConnection())->toBe('iam')->and($context->current()?->name)->toBe('iam');

    $context->switchToModuleOf(Str::class);
    expect(DB::getDefaultConnection())->toBe($default)->and($context->current())->toBeNull();
});
