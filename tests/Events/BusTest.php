<?php

declare(strict_types=1);

use Apps\Iam\Events\UserAudited;
use Apps\Iam\Events\UserIgnored;
use Apps\Iam\Events\UserRegistered;
use Apps\Iam\Support\Recorder;
use Distributable\Tests\Support\ModuleAppTestCase;
use Distributable\Tests\Support\RecordingTransport;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Microservices\Contracts\Stream\Bus;
use Microservices\Contracts\Stream\Transport;
use Microservices\Data\Envelope;
use Microservices\Exceptions\ConfigurationException;
use Microservices\Services\Stream\Dispatcher;
use Microservices\Services\Stream\TransportManager;

uses(ModuleAppTestCase::class);

beforeEach(function () {
    Config::set('microservices.events.streams.default.driver', 'array');
    $this->app->singleton(Recorder::class);

    // A consumer-supplied transport, registered exactly as a real one would be.
    $this->app->instance(RecordingTransport::class, new RecordingTransport);
    $this->app->make(TransportManager::class)->extend(
        'recording',
        fn ($app): Transport => $app->make(RecordingTransport::class),
    );
});

it('delivers an emitted event to the listening handlers once the stream is consumed', function () {
    $this->app->make(Bus::class)->emit(new UserRegistered(5, 'lamp'));

    expect($this->app->make(Recorder::class)->records)->toBe([]);

    $this->artisan('microservices:events:consume --module=iam')->assertSuccessful();

    expect($this->app->make(Recorder::class)->records)
        ->toBe([['iam.user.registered', ['id' => 5, 'name' => 'lamp']]]);
});

it('hands a module an event in a test, without the emitter or the stream', function () {
    $this->receive('iam', 'iam.user.registered', ['id' => 7, 'name' => 'desk']);

    $recorder = $this->app->make(Recorder::class);

    expect($recorder->records)->toBe([['iam.user.registered', ['id' => 7, 'name' => 'desk']]])
        ->and($recorder->connections)->toBe(['iam']);
});

it('ignores an event nobody listens to', function () {
    $this->app->make(Bus::class)->emit(new UserIgnored(5));
    $this->artisan('microservices:events:consume --module=iam')->assertSuccessful();

    expect($this->app->make(Recorder::class)->records)->toBe([]);
});

it('drops every event on the null transport', function () {
    Config::set('microservices.events.streams.default.driver', 'null');

    $this->app->make(Bus::class)->emit(new UserRegistered(5, 'lamp'));
    $this->artisan('microservices:events:consume --module=iam')->assertSuccessful();

    expect($this->app->make(Recorder::class)->records)->toBe([]);
});

it('stamps the envelope with the emitting module and a unique id', function () {
    Config::set('microservices.events.streams.default.driver', 'recording');
    $transport = $this->app->make(RecordingTransport::class);

    $bus = $this->app->make(Bus::class);
    $bus->emit(new UserRegistered(5, 'lamp'));
    $bus->emit(new UserRegistered(6, 'chair'));

    expect($transport->published)->toHaveCount(2)
        ->and($transport->published[0]->emitter)->toBe('iam')
        ->and($transport->published[0]->name)->toBe('iam.user.registered')
        ->and($transport->published[0]->payload)->toBe(['id' => 5, 'name' => 'lamp'])
        ->and($transport->published[0]->id)->not->toBe($transport->published[1]->id);
});

it('carries only the configured context keys in the headers', function () {
    Config::set('microservices.events.streams.default.driver', 'recording');
    Config::set('microservices.events.propagate', ['trace_id']);
    $transport = $this->app->make(RecordingTransport::class);

    Context::add(['trace_id' => 'abc', 'secret' => 'kept-local']);
    $this->app->make(Bus::class)->emit(new UserRegistered(5, 'lamp'));

    expect($transport->published[0]->headers)->toBe(['trace_id' => 'abc']);
});

it('restores the propagated context around the handler, then the caller one', function () {
    Config::set('microservices.events.propagate', ['trace_id']);
    Context::add('trace_id', 'abc');

    $this->app->make(Dispatcher::class)->dispatch(
        new Envelope('00000000-0000-0000-0000-000000000001', 'iam', 'iam.user.registered', ['id' => 5, 'name' => 'lamp'], ['trace_id' => 'remote'], now()->toImmutable()),
    );

    expect($this->app->make(Recorder::class)->traces)->toBe(['remote'])
        ->and(Context::get('trace_id'))->toBe('abc');
});

it('runs a handler on the database of its own module, then puts the caller one back', function () {
    $default = DB::getDefaultConnection();

    $this->app->make(Dispatcher::class)->dispatch(
        new Envelope('00000000-0000-0000-0000-000000000002', 'iam', 'iam.user.registered', ['id' => 5, 'name' => 'lamp'], [], now()->toImmutable()),
    );

    expect($this->app->make(Recorder::class)->connections)->toBe(['iam'])
        ->and(DB::getDefaultConnection())->toBe($default);
});

it('round-trips an envelope through the wire format', function () {
    $envelope = Envelope::for(new UserRegistered(5, 'lamp'), 'iam', ['trace_id' => 'abc']);

    $decoded = Envelope::fromJson($envelope->toJson());

    expect($decoded->id)->toBe($envelope->id)
        ->and($decoded->emitter)->toBe('iam')
        ->and($decoded->name)->toBe('iam.user.registered')
        ->and($decoded->payload)->toBe(['id' => 5, 'name' => 'lamp'])
        ->and($decoded->headers)->toBe(['trace_id' => 'abc'])
        ->and($decoded->emittedAt->equalTo($envelope->emittedAt))->toBeTrue();
});

it('lets a consumer plug its own transport without touching the kernel', function () {
    Config::set('microservices.events.streams.default.driver', 'recording');

    expect($this->app->make(Transport::class))->toBeInstanceOf(RecordingTransport::class);
});

it('fails loudly on a transport nobody registered', function () {
    Config::set('microservices.events.streams.default.driver', 'kafka');

    $this->app->make(Bus::class)->emit(new UserRegistered(5, 'lamp'));
})->throws(ConfigurationException::class, 'Stream driver [kafka] of stream [default] is not supported');

it('fails loudly on a stream nobody declared', function () {
    $this->app->make(TransportManager::class)->stream('payments');
})->throws(ConfigurationException::class, 'Stream [payments] is not declared');

it('puts an event on the stream it names, declared by its module', function () {
    $this->app->make(Bus::class)->emit(new UserAudited(7));

    $audit = $this->app->make(TransportManager::class)->stream('audit');
    $received = [];
    $audit->consume('analytics', ['iam'], function (Envelope $envelope) use (&$received): void {
        $received[] = $envelope;
    });

    $default = [];
    $this->app->make(TransportManager::class)->stream()->consume('analytics', ['iam'], function (Envelope $envelope) use (&$default): void {
        $default[] = $envelope;
    });

    expect($received)->toHaveCount(1)
        ->and($received[0]->stream)->toBe('audit')
        ->and($received[0]->payload)->toBe(['id' => 7])
        ->and($default)->toBe([]);
});
