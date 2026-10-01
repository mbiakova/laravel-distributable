<?php

declare(strict_types=1);

use Modulith\Tests\TestCase;

uses(TestCase::class);

use Modulith\Config\Modules;

it('merges the modulith config with its defaults', function () {
    expect(config('modulith.modules'))->toBe([])
        ->and(config('modulith.runs'))->toBe('*')
        ->and(config('modulith.paths.modules'))->toBe('apps')
        ->and(config('modulith.namespaces.modules'))->toBe('Apps')
        ->and(config('modulith.events.streams.default.key'))->toBe('modulith:events')
        ->and(config('modulith.rpc.transport'))->toBe('http');
});

it('reads MODULITH_RUNS as a list of module names, * standing for every module', function () {
    config()->set('modulith.runs', ' transactions , analytics ,');

    expect(app(Modules::class)->getLoadedModules())->toBe(['transactions', 'analytics']);

    config()->set('modulith.runs', '*');

    expect(app(Modules::class)->getLoadedModules())->toBe(['*']);
});
