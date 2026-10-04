<?php

declare(strict_types=1);

use Apps\Iam\Models\User;
use Apps\Iam\Providers\IamServiceProvider;
use Distributable\Services\Modules\ModuleRegistry;
use Distributable\Tests\Support\ModuleAppTestCase;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;

uses(ModuleAppTestCase::class);

it('builds the registry from the configured source', function () {
    $registry = $this->app->make(ModuleRegistry::class);

    expect(array_map(fn ($module) => $module->name, $registry->all()))->toBe(['analytics', 'gateway', 'iam'])
        ->and($registry->get('gateway')->hasDatabase)->toBeFalse();
});

it('answers which modules this process runs on the status route', function () {
    $this->get('/')->assertOk()->assertExactJson([
        'message' => 'Hello from laravel-distributable',
        'modules' => ['analytics', 'gateway', 'iam'],
        'status' => 'ok',
    ]);
});

it('serves a module route file under the {module}/{file} prefix', function () {
    $this->get('/iam/api/v1/ping')->assertOk()->assertContent('pong');
});

it('wraps a route file in the middleware group of the same name when one exists', function () {
    $route = $this->app->make(Router::class)->getRoutes()->match(Request::create('/iam/admin/hello'));

    expect($route->gatherMiddleware())->toContain('admin');
});

it('serves the RPC endpoint of each local module inside the signed rpc group', function () {
    $route = $this->app->make(Router::class)->getRoutes()->match(Request::create('/iam/rpc/findUser', 'POST'));

    expect($route->gatherMiddleware())->toContain('rpc')
        ->and($route->defaults['service'])->toBe('iam');
});

it('deep-merges the module config over the root config, and applies the values it overrides while the module runs', function () {
    expect(config('iam.flag'))->toBeTrue()
        ->and(config('iam.items'))->toBe(['from-root', 'from-module'])
        ->and(config('iam.nested'))->toBe(['kept' => 'root', 'override' => 'root'])
        ->and($this->inModule('iam', fn () => config('iam.nested')))->toBe(['kept' => 'root', 'override' => 'module'])
        ->and($this->inModule('analytics', fn () => config('iam.nested.override')))->toBe('root');
});

it('merges an array keyed by integers key by key, as it does any other map', function () {
    expect($this->inModule('iam', fn () => config('iam.codes')))->toBe([403 => 'root forbidden', 404 => 'module not found']);
});

it('appends a list item once, however many times it is declared', function () {
    $this->app->register(IamServiceProvider::class, force: true);

    expect(config('iam.items'))->toBe(['from-root', 'from-module']);
});

it('keeps the cached config as it is, instead of merging the module config over it again', function () {
    $this->app->instance('config_loaded_from_cache', true);
    config()->set('iam.flag', 'cached');

    $this->app->register(IamServiceProvider::class, force: true);

    expect(config('iam.flag'))->toBe('cached');
});

it('loads the module translations under the module namespace', function () {
    expect(__('iam::messages.hello'))->toBe('Hello from iam');
});

it('keeps the module migrations out of the application migrator, so they never land in its database', function () {
    /** @var Migrator $migrator */
    $migrator = $this->app->make('migrator');

    expect($migrator->paths())
        ->not->toContain(dirname(__DIR__).'/Fixtures/apps/Iam/database/migrations');
});

it('autoloads a module namespace from its app directory, with no composer.json entry', function () {
    expect(class_exists(User::class))->toBeTrue()
        ->and(new ReflectionClass(User::class)->getFileName())
        ->toBe(dirname(__DIR__).'/Fixtures/apps/Iam/app/Models/User.php');
});

it('registers the module console commands', function () {
    $this->artisan('iam:ping')->expectsOutput('pong')->assertSuccessful();
});
