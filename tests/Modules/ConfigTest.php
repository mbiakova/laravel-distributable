<?php

declare(strict_types=1);

use Modulith\Tests\TestCase;

uses(TestCase::class);

use Modulith\Config\Modules;
use Modulith\Services\Modules\ManifestSource;

it('merges the modulith config with its defaults', function () {
    expect(config('modulith.source'))->toBe(ManifestSource::class)
        ->and(config('modulith.with'))->toBe('*')
        ->and(config('modulith.modules_path'))->toBe('apps')
        ->and(config('modulith.modules_namespace'))->toBe('Apps');
});

it('reads WITH_MODULES as a list of module names, * standing for every module', function () {
    config()->set('modulith.with', ' transactions , analytics ,');

    expect(app(Modules::class)->getLoadedModules())->toBe(['transactions', 'analytics']);

    config()->set('modulith.with', '*');

    expect(app(Modules::class)->getLoadedModules())->toBe(['*']);
});
