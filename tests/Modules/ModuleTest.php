<?php

declare(strict_types=1);

use Modulith\Tests\TestCase;

uses(TestCase::class);

use Modulith\Data\Module;
use Modulith\Exceptions\ModuleException;

it('derives every convention from the module name', function () {
    $module = Module::fromName('point_of_sale', 'Modules');

    expect($module->name)->toBe('point_of_sale')
        ->and($module->namespace)->toBe('Modules\PointOfSale')
        ->and($module->provider)->toBe('Modules\PointOfSale\Providers\PointOfSaleServiceProvider')
        ->and($module->path())->toBe(base_path('modules/PointOfSale'))
        ->and($module->classPath())->toBe(base_path('modules/PointOfSale/app'))
        ->and($module->connection())->toBe('point_of_sale')
        ->and($module->ownerConnection())->toBe('point_of_sale_owner');
});

it('has no database when the module declares no config/database.php', function () {
    expect(Module::fromName('point_of_sale', 'Modules')->hasDatabase)->toBeFalse();
});

it('rejects a name that is not lowercase snake_case', function (string $name) {
    Module::fromName($name, 'Modules');
})->throws(ModuleException::class, 'Invalid module name')->with(['Iam', 'my-shop', '1shop', '']);
