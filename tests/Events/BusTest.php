<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Context;
use Modules\Iam\Events\UserIgnored;
use Modules\Iam\Events\UserRegistered;
use Modules\Iam\Support\Recorder;
use Modulith\Contracts\Bus;
use Modulith\Contracts\Transport;
use Modulith\Data\Envelope;
use Modulith\Services\Dispatcher;
use Modulith\Services\TransportManager;
use Modulith\Tests\Support\ModuleAppTestCase;
use Modulith\Tests\Support\RecordingTransport;

uses(ModuleAppTestCase::class);

beforeEach(function () {
    Config::set('streamer.transport', 'array');
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

    $this->artisan('modulith:events:consume --module=iam')->assertSuccessful();

    expect($this->app->make(Recorder::class)->records)
        ->toBe([['iam.user.registered', ['id' => 5, 'name' => 'lamp']]]);
});

it('ignores an event nobody listens to', function () {
    $this->app->make(Bus::class)->emit(new UserIgnored(5));
    $this->artisan('modulith:events:consume --module=iam')->assertSuccessful();

    expect($this->app->make(Recorder::class)->records)->toBe([]);
});

it('drops every event on the null transport', function () {
    Config::set('streamer.transport', 'null');

    $this->app->make(Bus::class)->emit(new UserRegistered(5, 'lamp'));
    $this->artisan('modulith:events:consume --module=iam')->assertSuccessful();

    expect($this->app->make(Recorder::class)->records)->toBe([]);
});

it('stamps the envelope with the emitting module and a unique id', function () {
    Config::set('streamer.transport', 'recording');
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
    Config::set('streamer.transport', 'recording');
    Config::set('streamer.propagate', ['trace_id']);
    $transport = $this->app->make(RecordingTransport::class);

    Context::add(['trace_id' => 'abc', 'secret' => 'kept-local']);
    $this->app->make(Bus::class)->emit(new UserRegistered(5, 'lamp'));

    expect($transport->published[0]->headers)->toBe(['trace_id' => 'abc']);
});

it('restores the propagated context around the handler, then the caller one', function () {
    Config::set('streamer.propagate', ['trace_id']);
    Context::add('trace_id', 'abc');

    $this->app->make(Dispatcher::class)->dispatch(
        new Envelope('00000000-0000-0000-0000-000000000001', 'iam', 'iam.user.registered', ['id' => 5, 'name' => 'lamp'], ['trace_id' => 'remote'], now()->toImmutable()),
    );

    expect($this->app->make(Recorder::class)->traces)->toBe(['remote'])
        ->and(Context::get('trace_id'))->toBe('abc');
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
    Config::set('streamer.transport', 'recording');

    expect($this->app->make(Transport::class))->toBeInstanceOf(RecordingTransport::class);
});

it('fails loudly on a transport nobody registered', function () {
    Config::set('streamer.transport', 'kafka');

    $this->app->make(Bus::class)->emit(new UserRegistered(5, 'lamp'));
})->throws(InvalidArgumentException::class, 'Driver [kafka] not supported.');
