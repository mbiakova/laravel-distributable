<?php

declare(strict_types=1);

use Distributable\Tests\TestCase;

uses(TestCase::class);

use Distributable\Data\Module;
use Distributable\Exceptions\ModuleException;
use Distributable\Services\Modules\ModuleRegistry;

/** @param list<string> $loadedModules */
function registryLoading(array $loadedModules = ['*']): ModuleRegistry
{
    return new ModuleRegistry([
        Module::fromName('iam', 'Apps', 'apps'),
        Module::fromName('analytics', 'Apps', 'apps'),
    ], $loadedModules);
}

it('treats every module as local when RUN_MODULES is *', function () {
    $registry = registryLoading(['*']);

    expect($registry->local())->toHaveCount(2)
        ->and($registry->isLocal('analytics'))->toBeTrue();
});

it('narrows the local set to the RUN_MODULES list', function () {
    $registry = registryLoading(['analytics']);

    $local = $registry->local();

    expect($local)->toHaveCount(1)
        ->and($local[0]->name)->toBe('analytics')
        ->and($registry->isLocal('iam'))->toBeFalse();
});

it('fails loudly on an unknown module name', function () {
    registryLoading(['analytics', 'transactions'])->local();
})->throws(ModuleException::class, 'Unknown module [transactions]');

it('resolves the module owning a class from its namespace', function () {
    $registry = registryLoading();

    expect($registry->forClass('Apps\Analytics\Models\Report')->name)->toBe('analytics')
        ->and($registry->forClass('Illuminate\Support\Str'))->toBeNull();
});
