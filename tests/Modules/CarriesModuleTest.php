<?php

declare(strict_types=1);

use Apps\Analytics\Reports\EntersIam;
use ArrayObject;
use Carbon\CarbonInterval;
use Distributable\Exceptions\ModuleException;
use Distributable\Services\Modules\ModuleContext;
use Distributable\Support\ModuleConcurrencyDriver;
use Distributable\Support\ModuleTaskDispatcher;
use Distributable\Tests\Support\DeferredReportsProvider;
use Distributable\Tests\Support\ModuleAppTestCase;
use Illuminate\Contracts\Concurrency\Driver;
use Illuminate\Support\Defer\DeferredCallback;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Octane\Contracts\DispatchesTasks;
use Laravel\SerializableClosure\SerializableClosure;

uses(ModuleAppTestCase::class);

it('refuses a module code that enters another module, directly or through Colocation', function () {
    $analytics = app(EntersIam::class);

    expect(fn () => $analytics->directly('iam'))->toThrow(ModuleException::class, 'makes analytics code run in module [iam]')
        ->and(fn () => $analytics->throughColocation('iam'))->toThrow(ModuleException::class, 'makes analytics code run in module [iam]')
        ->and($analytics->directly('analytics'))->toBe('entered')
        ->and($this->inModule('iam', static fn (): string => 'entered'))->toBe('entered');
});

it('gives each module its own instance of a per_module service, built once and swapped in as it runs', function () {
    config()->set('distributable.per_module', ['cache', 'cache.store']);

    $this->inModule('iam', fn () => Cache::put('owner', 'iam'));
    $this->inModule('analytics', fn () => Cache::put('owner', 'analytics'));
    $iam = $this->inModule('iam', fn () => app('cache'));

    expect($this->inModule('iam', fn () => Cache::get('owner')))->toBe('iam')
        ->and($this->inModule('analytics', fn () => Cache::get('owner')))->toBe('analytics')
        ->and(Cache::get('owner'))->toBeNull()
        ->and($this->inModule('iam', fn () => app('cache')))->toBe($iam);
});

it('builds a per_module service of a deferred provider on the first switch, through its extenders', function () {
    app()->addDeferredServices(['reports' => DeferredReportsProvider::class]);
    app()->extend('reports', static function (ArrayObject $reports): ArrayObject {
        $reports['extended'] = true;

        return $reports;
    });
    config()->set('distributable.per_module', ['reports']);

    $iam = $this->inModule('iam', fn () => app('reports'));
    $analytics = $this->inModule('analytics', fn () => app('reports'));

    expect($iam)->not->toBe($analytics)
        ->and($iam['extended'] ?? false)->toBeTrue()
        ->and($analytics['extended'] ?? false)->toBeTrue()
        ->and($this->inModule('iam', fn () => app('reports')))->toBe($iam);
});

it('runs a scheduled closure in the module that declared it, then leaves the module', function () {
    $this->artisan('schedule:run')->assertSuccessful();

    expect(Cache::get('scheduled-connection'))->toBe('iam')
        ->and(DB::getDefaultConnection())->toBe(ModuleContext::NO_MODULE);
});

it('runs a queued closure in the module that queued it', function () {
    $this->inModule('iam', static function (): void {
        dispatch(static fn () => Cache::put('connection', DB::getDefaultConnection()));
    });

    expect(Cache::get('connection'))->toBe('iam');
});

it('runs a deferred callback in the module that deferred it, after that module handed back', function () {
    $this->inModule('iam', fn () => defer(fn () => Cache::put('connection', DB::getDefaultConnection())));

    expect(DB::getDefaultConnection())->not->toBe('iam');

    app(DeferredCallbackCollection::class)->invoke();

    expect(Cache::get('connection'))->toBe('iam');
});

it('gives a task its module, even serialized and run where no module is set', function () {
    // One closure per line: SerializableClosure finds a closure's code by its line.
    $read = static fn (): string => DB::getDefaultConnection();
    $task = $this->inModule('iam', static fn () => app(ModuleContext::class)->bind($read));

    /** @var Closure(): string $elsewhere */
    $elsewhere = unserialize(serialize(new SerializableClosure($task)))->getClosure();

    expect(app(ModuleContext::class)->current())->toBeNull()
        ->and($elsewhere())->toBe('iam')
        ->and(app(ModuleContext::class)->current())->toBeNull();
});

it('gives each Concurrency task the module of its caller', function () {
    $isolated = new class implements Driver
    {
        public function run(Closure|array $tasks, CarbonInterval|int|null $timeout = null): array
        {
            app(ModuleContext::class)->switchTo(null); // a child process starts with no module

            return array_map(fn (Closure $task): mixed => $task(), (array) $tasks);
        }

        public function defer(Closure|array $tasks): DeferredCallback
        {
            return new DeferredCallback(fn () => null);
        }
    };

    $driver = new ModuleConcurrencyDriver($isolated, app(ModuleContext::class));

    expect($this->inModule('iam', fn () => $driver->run([fn (): string => DB::getDefaultConnection()])))->toBe(['iam']);
});

it('hands Octane a dispatcher that binds each task to its module', function () {
    expect(app(DispatchesTasks::class))->toBeInstanceOf(ModuleTaskDispatcher::class)
        ->and($this->inModule('iam', fn () => app(DispatchesTasks::class)->resolve(['a' => fn (): string => DB::getDefaultConnection()])))
        ->toBe(['a' => 'iam']);
});
