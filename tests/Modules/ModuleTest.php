<?php

declare(strict_types=1);

use Modulith\Tests\TestCase;

uses(TestCase::class);

use Modulith\Data\Module;
use Modulith\Exceptions\ModuleException;

it('derives every convention from the module name', function () {
    $module = Module::fromName('point_of_sale', 'Apps', 'apps');

    expect($module->name)->toBe('point_of_sale')
        ->and($module->namespace)->toBe('Apps\PointOfSale')
        ->and($module->provider)->toBe('Apps\PointOfSale\Providers\PointOfSaleServiceProvider')
        ->and($module->path())->toBe(base_path('apps/PointOfSale'))
        ->and($module->classPath())->toBe(base_path('apps/PointOfSale/app'))
        ->and($module->connection())->toBe('point_of_sale')
        ->and($module->ownerConnection())->toBe('point_of_sale_owner');
});

it('has no database when no connection is named after the module', function () {
    expect(Module::fromName('point_of_sale', 'Apps', 'apps')->hasDatabase)->toBeFalse();
});

it('has a database once the root config/database.php declares a connection named after the module', function () {
    config()->set('database.connections.point_of_sale', ['driver' => 'sqlite', 'database' => ':memory:']);

    expect(Module::fromName('point_of_sale', 'Apps', 'apps')->hasDatabase)->toBeTrue();
});

it('rejects a name that is not lowercase snake_case', function (string $name) {
    Module::fromName($name, 'Apps', 'apps');
})->throws(ModuleException::class, 'Invalid module name')->with(['Iam', 'my-shop', '1shop', '']);
