<?php

declare(strict_types=1);

use Apps\Iam\Events\UserRegistered;
use Apps\Iam\Handlers\OnUserRegistered;
use Apps\Iam\Support\Recorder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Microservices\Data\Envelope;
use Microservices\Providers\MicroservicesServiceProvider;
use Microservices\Services\Stream\Dispatcher;
use Modulith\Tests\Support\ModuleAppTestCase;

uses(ModuleAppTestCase::class);

beforeEach(function () {
    $this->app->singleton(Recorder::class);
    Config::set('microservices.events.guard', true);

    Config::set('database.default', 'iam');
    foreach (glob(dirname((string) (new ReflectionClass(MicroservicesServiceProvider::class))->getFileName(), 3).'/database/migrations/*.php') as $file) {
        (require $file)->up();
    }
});

it('runs a handler once and turns the redelivery into a no-op', function () {
    $envelope = Envelope::for(new UserRegistered(5, 'ada'), 'iam');
    $dispatcher = $this->app->make(Dispatcher::class);

    $dispatcher->dispatch($envelope);
    $dispatcher->dispatch($envelope); // the broker hands the same entry over again

    expect($this->app->make(Recorder::class)->records)->toHaveCount(1)
        ->and(DB::connection('iam')->table('event_consumptions')->count())->toBe(1);
});

it('marks per handler, not per event', function () {
    Config::set('microservices.events.listen', [
        'iam.user.registered' => [OnUserRegistered::class, OnUserRegistered::class],
    ]);

    $this->app->make(Dispatcher::class)->dispatch(Envelope::for(new UserRegistered(5, 'ada'), 'iam'));

    // Same handler class twice in the map is one guarded slot: the second is already claimed.
    expect(DB::connection('iam')->table('event_consumptions')->count())->toBe(1);
});

it('leaves no mark when the handler fails, so the event is replayed', function () {
    $this->app->bind(OnUserRegistered::class, fn () => throw new RuntimeException('handler blew up'));

    $envelope = Envelope::for(new UserRegistered(5, 'ada'), 'iam');

    expect(fn () => $this->app->make(Dispatcher::class)->dispatch($envelope))
        ->toThrow(RuntimeException::class);

    expect(DB::connection('iam')->table('event_consumptions')->count())->toBe(0);
});

it('stays off when delivery is exactly-once', function () {
    Config::set('microservices.events.guard', null);
    Config::set('microservices.events.streams.default.outbox', false);
    Config::set('microservices.events.streams.default.driver', 'array');

    expect($this->app->make(Dispatcher::class)->guarded())->toBeFalse();
});

it('turns itself on when the outbox is on', function () {
    Config::set('microservices.events.guard', null);
    Config::set('microservices.events.streams.default.outbox', true);

    expect($this->app->make(Dispatcher::class)->guarded())->toBeTrue();
});
