<?php

declare(strict_types=1);

use Modulith\Tests\TestCase;

uses(TestCase::class);

use Modulith\Contracts\Modules\Source;
use Modulith\Data\Module;
use Modulith\Exceptions\ModuleException;
use Modulith\Services\Modules\ModuleRegistry;

/** @param list<string> $loadedModules */
function registryLoading(array $loadedModules = ['*']): ModuleRegistry
{
    $source = new class implements Source
    {
        public function modules(): array
        {
            return [
                Module::fromName('iam', 'Apps', 'apps'),
                Module::fromName('analytics', 'Apps', 'apps'),
            ];
        }
    };

    return new ModuleRegistry($source, $loadedModules);
}

it('treats every module as local when WITH_MODULES is *', function () {
    $registry = registryLoading(['*']);

    expect($registry->local())->toHaveCount(2)
        ->and($registry->isLocal('analytics'))->toBeTrue();
});

it('narrows the local set to the WITH_MODULES list', function () {
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
