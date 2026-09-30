<?php

declare(strict_types=1);

use Modulith\Tests\TestCase;

uses(TestCase::class);

use Modulith\Services\Modules\ManifestSource;

it('discovers one module per directory carrying a manifest', function () {
    $source = new ManifestSource(dirname(__DIR__).'/Fixtures/apps', 'Apps');

    $names = array_map(fn ($module) => $module->name, $source->modules());

    expect($names)->toBe(['analytics', 'gateway', 'iam']);
});

it('derives each module from its directory, with a database when it declares one', function () {
    $source = new ManifestSource(dirname(__DIR__).'/Fixtures/apps', 'Apps');

    [, $gateway, $iam] = $source->modules();

    expect($iam->hasDatabase)->toBeTrue()
        ->and($iam->namespace)->toBe('Apps\Iam')
        ->and($iam->provider)->toBe('Apps\Iam\Providers\IamServiceProvider')
        ->and($iam->path())->toBe(dirname(__DIR__).'/Fixtures/apps/Iam')
        ->and($iam->classPath())->toBe(dirname(__DIR__).'/Fixtures/apps/Iam/app')
        ->and($gateway->hasDatabase)->toBeFalse();
});
