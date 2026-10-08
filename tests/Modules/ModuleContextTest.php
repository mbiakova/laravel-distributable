<?php

declare(strict_types=1);

use Apps\Iam\Http\Controllers\ConnectionController;
use Apps\Iam\Jobs\RecordConnection;
use Apps\Iam\Listeners\RecordEventConnection;
use Distributable\Exceptions\ModuleException;
use Distributable\Services\Modules\ModuleContext;
use Distributable\Tests\Support\ModuleAppTestCase;
use Distributable\Tests\Support\SomethingCommitted;
use Distributable\Tests\Support\SomethingHappened;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

// As a real `php artisan` run does, so CommandStarting fires.
uses(ModuleAppTestCase::class, WithConsoleEvents::class);

it('runs a module route on the module connection', function () {
    $this->get('/iam/api/v1/connection')->assertOk()->assertSee('iam');
});

it('runs a module controller in its module, from a route declared outside the module', function () {
    Route::get('outside/connection', ConnectionController::class);
    Route::get('outside/closure', fn (): string => DB::getDefaultConnection());

    $this->get('/outside/closure')->assertOk()->assertContent(ModuleContext::NO_MODULE);
    $this->get('/outside/connection')->assertOk()->assertContent('iam');
});

it('runs a module command on the module connection', function () {
    $this->artisan('iam:connection')->expectsOutput('iam')->assertSuccessful();
});

it('runs a module job on the module connection', function () {
    RecordConnection::$seen = null;

    dispatch(new RecordConnection);

    expect(RecordConnection::$seen)->toBe('iam');
});

it('runs the Laravel listeners a module declares in its module, whoever dispatches the event', function () {
    RecordEventConnection::$seen = [];

    $this->inModule('analytics', fn () => event(new SomethingHappened));

    expect(RecordEventConnection::$seen)->toBe(['handle:iam', 'remember:iam', 'queued:iam']);
});

it('runs an after-commit listener in its module once the other module transaction commits', function () {
    RecordEventConnection::$seen = [];

    $this->inModule('analytics', fn () => DB::transaction(function (): void {
        event(new SomethingCommitted);

        expect(RecordEventConnection::$seen)->toBe([]);
    }));

    expect(RecordEventConnection::$seen)->toBe(['after-commit:iam']);
});

it('runs a piece of a test in the module of a class, so the test names no module', function () {
    expect($this->inModuleOf(RecordConnection::class, fn (): string => DB::getDefaultConnection()))->toBe('iam');

    $this->inModuleOf(Str::class, fn () => null);
})->throws(ModuleException::class, 'is not inside a module namespace');

it('tells which listeners a module attached, where Event::assertListening() only sees a closure', function () {
    $this->assertListeningInModule(SomethingHappened::class, RecordEventConnection::class);
    $this->assertListeningInModule(SomethingHappened::class, RecordEventConnection::class.'@remember');
});

it('keeps the context connection when a module config sets database.default', function () {
    config()->set('distributable.overlay_base.database.default', 'sqlite');
    config()->set('distributable.overlays.iam.database.default', 'elsewhere');

    app(ModuleContext::class)->switchTo(null);

    expect(DB::getDefaultConnection())->toBe(ModuleContext::NO_MODULE)
        ->and($this->inModule('iam', fn () => DB::getDefaultConnection()))->toBe('iam')
        ->and(DB::getDefaultConnection())->toBe(ModuleContext::NO_MODULE);
});

it('gives the application its own connection back outside any module', function () {
    $default = DB::getDefaultConnection();
    $context = app(ModuleContext::class);

    $context->switchToModuleOf(RecordConnection::class);
    expect(DB::getDefaultConnection())->toBe('iam')->and($context->current()?->name)->toBe('iam');

    $context->switchToModuleOf(Str::class);
    expect(DB::getDefaultConnection())->toBe($default)->and($context->current())->toBeNull();
});
