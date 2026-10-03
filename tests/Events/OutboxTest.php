<?php

declare(strict_types=1);

use Apps\Iam\Events\UserAudited;
use Apps\Iam\Events\UserRegistered;
use Apps\Iam\Support\Recorder;
use Distributable\Tests\Support\ExplodingTransport;
use Distributable\Tests\Support\ModuleAppTestCase;
use Distributable\Tests\Support\RecordingTransport;
use Distributable\Tests\Support\TrackingTransport;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Microservices\Contracts\Stream\Bus;
use Microservices\Contracts\Stream\Transport;
use Microservices\Data\Envelope;
use Microservices\Exceptions\ConfigurationException;
use Microservices\Providers\MicroservicesServiceProvider;
use Microservices\Services\Stream\Outbox\Relay;
use Microservices\Services\Stream\TransportManager;

uses(ModuleAppTestCase::class);

beforeEach(function () {
    Config::set('microservices.events.streams.default.outbox', true);
    Config::set('microservices.events.streams.default.driver', 'recording');

    $this->app->instance(RecordingTransport::class, new RecordingTransport);
    $this->app->make(TransportManager::class)->extend(
        'recording',
        fn ($app): Transport => $app->make(RecordingTransport::class),
    );
    $this->app->make(TransportManager::class)->extend(
        'exploding',
        fn (): Transport => new ExplodingTransport,
    );

    $this->app->singleton(Recorder::class);

    // laravel-microservices' outbox migrations, on the iam module's database.
    Config::set('database.default', 'iam');
    foreach (glob(dirname((string) (new ReflectionClass(MicroservicesServiceProvider::class))->getFileName(), 3).'/database/migrations/*.php') as $file) {
        (require $file)->up();
    }
});

function publications(): Builder
{
    return DB::connection('iam')->table('event_publications');
}

it('writes a publication instead of publishing straight away', function () {
    $this->app->make(Bus::class)->emit(new UserRegistered(5, 'lamp'));

    $row = publications()->first();

    expect($row->name)->toBe('iam.user.registered')
        ->and($row->emitter)->toBe('iam')
        ->and(json_decode((string) $row->payload, true))->toBe(['id' => 5, 'name' => 'lamp']);
});

it('publishes straight away on a stream without outbox', function () {
    $this->app->make(Bus::class)->emit(new UserAudited(7));

    $received = [];
    $this->app->make(TransportManager::class)->stream('audit')->consume('analytics', ['iam'], function (Envelope $envelope) use (&$received): void {
        $received[] = $envelope->payload;
    });

    expect($received)->toBe([['id' => 7]])
        ->and(publications()->count())->toBe(0);
});

it('publishes nothing when the business transaction rolls back', function () {
    try {
        DB::connection('iam')->transaction(function (): void {
            $this->app->make(Bus::class)->emit(new UserRegistered(5, 'lamp'));

            throw new RuntimeException('business failure');
        });
    } catch (RuntimeException) {
        // expected
    }

    // No ghost event, and no orphan row: one transaction, one outcome.
    expect(publications()->count())->toBe(0)
        ->and($this->app->make(RecordingTransport::class)->published)->toBe([]);
});

it('leaves putting the envelope on the wire to the publisher', function () {
    DB::connection('iam')->transaction(function (): void {
        $this->app->make(Bus::class)->emit(new UserRegistered(5, 'lamp'));
    });

    expect($this->app->make(RecordingTransport::class)->published)->toBe([])
        ->and(publications()->first()->published_at)->toBeNull();
});

it('publishes pending rows in emission order', function () {
    $this->app->make(Bus::class)->emit(new UserRegistered(1, 'first'));
    $this->app->make(Bus::class)->emit(new UserRegistered(2, 'second'));

    $published = $this->app->make(Relay::class)->drain('iam');

    expect($published)->toBe(2)
        ->and(array_map(fn (Envelope $e) => $e->payload['id'], $this->app->make(RecordingTransport::class)->published))->toBe([1, 2])
        ->and(publications()->whereNull('published_at')->count())->toBe(0);
});

it('sweeps every local module database from the command', function () {
    $envelope = Envelope::for(new UserRegistered(5, 'lamp'), 'iam');
    publications()->insert([
        'id' => $envelope->id,
        'emitter' => 'iam',
        'name' => $envelope->name,
        'payload' => json_encode($envelope->payload),
        'headers' => '[]',
        'emitted_at' => $envelope->emittedAt,
        'recipients' => '[]',
        'stream' => 'default',
    ]);

    $this->artisan('microservices:events:publish --once --module=iam')->assertSuccessful();

    expect($this->app->make(RecordingTransport::class)->published)->toHaveCount(1)
        ->and(publications()->first()->published_at)->not->toBeNull();
});

it('stops the pass at the first failure so no later row overtakes it', function () {
    Config::set('microservices.events.streams.default.driver', 'exploding');

    $this->app->make(Bus::class)->emit(new UserRegistered(1, 'first'));
    $this->app->make(Bus::class)->emit(new UserRegistered(2, 'second'));

    $published = $this->app->make(Relay::class)->drain('iam');

    [$first, $second] = publications()->orderBy('sequence')->get()->all();

    expect($published)->toBe(0)
        ->and((int) $first->attempts)->toBe(1)
        ->and($first->last_error)->toContain('transport down')
        ->and((int) $second->attempts)->toBe(0);
});

it('requeues published rows to rebuild an emptied stream', function () {
    $this->app->make(Bus::class)->emit(new UserRegistered(5, 'lamp'));
    $this->artisan('microservices:events:publish --once --module=iam')->assertSuccessful();

    $this->artisan('microservices:events:republish --force --module=iam')->assertSuccessful();

    expect(publications()->first()->published_at)->toBeNull();

    $this->artisan('microservices:events:publish --once --module=iam')->assertSuccessful();

    expect($this->app->make(RecordingTransport::class)->published)->toHaveCount(2);
});

it('exports only the rows matching a property of the payload', function () {
    $path = tempnam(sys_get_temp_dir(), 'distributable-export-');

    $this->app->make(Bus::class)->emit(new UserRegistered(1, 'keep'));
    $this->app->make(Bus::class)->emit(new UserRegistered(2, 'leave'));
    $this->artisan('microservices:events:publish --once --module=iam')->assertSuccessful();

    try {
        $this->artisan("microservices:events:export {$path} --module=iam --where=payload.name=keep")->assertSuccessful();

        expect(array_map(fn (string $line) => json_decode($line, true)['payload'], file($path)))->toBe(['{"id":1,"name":"keep"}'])
            ->and(publications()->pluck('payload')->all())->toBe(['{"id":2,"name":"leave"}']);
    } finally {
        unlink($path);
    }
});

it('exports only what every consumer acknowledged, stopping at the first row not yet read', function () {
    $path = tempnam(sys_get_temp_dir(), 'distributable-export-');
    $tracking = new TrackingTransport;
    $this->app->make(TransportManager::class)->extend('tracking', fn (): Transport => $tracking);
    Config::set('microservices.events.streams.default.driver', 'tracking');

    foreach ([1, 2, 3] as $id) {
        $this->app->make(Bus::class)->emit(new UserRegistered($id, "user-{$id}"));
    }
    $this->artisan('microservices:events:publish --once --module=iam')->assertSuccessful();
    $tracking->acknowledgedUpTo = 2;

    try {
        $this->artisan("microservices:events:export {$path} --module=iam --acknowledged --batch=1")->assertSuccessful();

        expect(count(file($path)))->toBe(2)
            ->and(publications()->pluck('stream_id')->all())->toBe(['3-0']);
    } finally {
        unlink($path);
    }
});

it('refuses --acknowledged on a transport that does not know who read what', function () {
    $this->app->make(Bus::class)->emit(new UserRegistered(1, 'a'));
    $this->artisan('microservices:events:publish --once --module=iam')->assertSuccessful();

    $this->artisan('microservices:events:export '.sys_get_temp_dir().'/distributable-refused.jsonl --module=iam --acknowledged');
})->throws(ConfigurationException::class, 'does not know who acknowledged what');

it('moves published rows to a file, in batches, and replays them from it', function () {
    $path = tempnam(sys_get_temp_dir(), 'distributable-export-');

    foreach ([1, 2, 3] as $id) {
        $this->app->make(Bus::class)->emit(new UserRegistered($id, "user-{$id}"));
    }
    $this->app->make(Bus::class)->emit(new UserRegistered(4, 'still-pending'));
    publications()->where('name', 'iam.user.registered')->whereIn('payload', ['{"id":1,"name":"user-1"}', '{"id":2,"name":"user-2"}', '{"id":3,"name":"user-3"}'])->update(['published_at' => now()]);

    try {
        $this->artisan("microservices:events:export {$path} --module=iam --batch=2")->assertSuccessful();

        expect(count(file($path)))->toBe(3)
            ->and(publications()->count())->toBe(1);

        $this->artisan("microservices:events:import {$path} --batch=2")->assertSuccessful();

        expect(publications()->whereNull('published_at')->orderBy('sequence')->pluck('payload')->map(fn ($p) => json_decode($p, true)['id'])->all())
            ->toBe([4, 1, 2, 3]);
    } finally {
        unlink($path);
    }
});
