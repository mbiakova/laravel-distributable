<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Modules\Iam\Models\User;
use Modules\Iam\Providers\IamServiceProvider;
use Modulith\Services\ModuleRegistry;
use Modulith\Tests\Support\ModuleAppTestCase;

uses(ModuleAppTestCase::class);

it('builds the registry from the configured source', function () {
    $registry = $this->app->make(ModuleRegistry::class);

    expect(array_map(fn ($module) => $module->name, $registry->all()))->toBe(['analytics', 'gateway', 'iam'])
        ->and($registry->get('gateway')->hasDatabase)->toBeFalse();
});

it('answers which modules this process runs on the status route', function () {
    $this->get('/')->assertOk()->assertExactJson([
        'message' => 'Hello from laravel-modulith',
        'modules' => ['analytics', 'gateway', 'iam'],
        'status' => 'ok',
    ]);
});

it('serves a module route file under the {module}/{file} prefix', function () {
    $this->get('/iam/api/v1/ping')->assertOk()->assertContent('pong');
});

it('wraps a route file in the middleware group of the same name when one exists', function () {
    $route = $this->app->make(Router::class)->getRoutes()->match(Request::create('/iam/rpc/v1/users/find', 'POST'));

    expect($route->gatherMiddleware())->toContain('rpc');
});

it('deep-merges the module config over the root config', function () {
    expect(config('iam.flag'))->toBeTrue()
        ->and(config('iam.items'))->toBe(['from-root', 'from-module'])
        ->and(config('iam.nested'))->toBe(['kept' => 'root', 'override' => 'module']);
});

it('appends a list item once, however many times it is declared', function () {
    $this->app->register(IamServiceProvider::class, force: true);

    expect(config('iam.items'))->toBe(['from-root', 'from-module']);
});

it('loads the module translations under the module namespace', function () {
    expect(__('iam::messages.hello'))->toBe('Hello from iam');
});

it('registers the module migrations path', function () {
    /** @var Migrator $migrator */
    $migrator = $this->app->make('migrator');

    expect($migrator->paths())
        ->toContain(dirname(__DIR__).'/Fixtures/modules/Iam/database/migrations');
});

it('autoloads a module namespace from its app directory, with no composer.json entry', function () {
    expect(class_exists(User::class))->toBeTrue()
        ->and(new ReflectionClass(User::class)->getFileName())
        ->toBe(dirname(__DIR__).'/Fixtures/modules/Iam/app/Models/User.php');
});

it('registers the module console commands', function () {
    $this->artisan('iam:ping')->expectsOutput('pong')->assertSuccessful();
});
