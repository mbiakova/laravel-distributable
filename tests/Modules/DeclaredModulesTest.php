<?php

declare(strict_types=1);

use Modulith\Services\Modules\ModuleRegistry;
use Modulith\Tests\Support\ModuleAppTestCase;

uses(ModuleAppTestCase::class);

it('knows the modules modulith.modules declares, and nothing else', function () {
    expect(array_map(fn ($module) => $module->name, app(ModuleRegistry::class)->all()))->toBe(['analytics', 'gateway', 'iam']);
});

it('derives each declared module from its folder, with a database when it declares one', function () {
    $registry = app(ModuleRegistry::class);
    $iam = $registry->get('iam');

    expect($iam->hasDatabase)->toBeTrue()
        ->and($iam->namespace)->toBe('Apps\Iam')
        ->and($iam->provider)->toBe('Apps\Iam\Providers\IamServiceProvider')
        ->and($iam->path())->toBe(dirname(__DIR__).'/Fixtures/apps/Iam')
        ->and($iam->classPath())->toBe(dirname(__DIR__).'/Fixtures/apps/Iam/app')
        ->and($registry->get('gateway')->hasDatabase)->toBeFalse();
});
