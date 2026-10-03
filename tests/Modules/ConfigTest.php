<?php

declare(strict_types=1);

use Distributable\Tests\TestCase;

uses(TestCase::class);

use Distributable\Config\Modules;

it('merges the distributable config with its defaults, and the microservices one next to it', function () {
    expect(config('distributable.modules'))->toBe([])
        ->and(config('distributable.runs'))->toBe('*')
        ->and(config('distributable.paths.modules'))->toBe('apps')
        ->and(config('distributable.namespaces.modules'))->toBe('Apps')
        ->and(config('microservices.events.streams.default.key'))->toBe('microservices:events')
        ->and(config('microservices.rpc.transport'))->toBe('http');
});

it('reads RUN_MODULES as a list of module names, * standing for every module', function () {
    config()->set('distributable.runs', ' transactions , analytics ,');

    expect(app(Modules::class)->getLoadedModules())->toBe(['transactions', 'analytics']);

    config()->set('distributable.runs', '*');

    expect(app(Modules::class)->getLoadedModules())->toBe(['*']);
});
