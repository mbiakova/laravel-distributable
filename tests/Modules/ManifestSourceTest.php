<?php

declare(strict_types=1);

use Modulith\Tests\TestCase;

uses(TestCase::class);

use Modulith\Services\ManifestSource;

it('discovers one module per directory carrying a manifest', function () {
    $source = new ManifestSource(dirname(__DIR__).'/Fixtures/modules', 'Modules');

    $names = array_map(fn ($module) => $module->name, $source->modules());

    expect($names)->toBe(['analytics', 'gateway', 'iam']);
});

it('derives each module from its directory, with a database when it declares one', function () {
    $source = new ManifestSource(dirname(__DIR__).'/Fixtures/modules', 'Modules');

    [, $gateway, $iam] = $source->modules();

    expect($iam->hasDatabase)->toBeTrue()
        ->and($iam->namespace)->toBe('Modules\Iam')
        ->and($iam->provider)->toBe('Modules\Iam\Providers\IamServiceProvider')
        ->and($iam->path())->toBe(dirname(__DIR__).'/Fixtures/modules/Iam')
        ->and($iam->classPath())->toBe(dirname(__DIR__).'/Fixtures/modules/Iam/app')
        ->and($gateway->hasDatabase)->toBeFalse();
});
