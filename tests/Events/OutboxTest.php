<?php

declare(strict_types=1);

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Modules\Iam\Events\UserRegistered;
use Modules\Iam\Support\Recorder;
use Modulith\Contracts\Bus;
use Modulith\Contracts\Transport;
use Modulith\Data\Envelope;
use Modulith\Services\ModuleRegistry;
use Modulith\Services\Outbox\Relay;
use Modulith\Services\TransportManager;
use Modulith\Tests\Support\ExplodingTransport;
use Modulith\Tests\Support\ModuleAppTestCase;
use Modulith\Tests\Support\RecordingTransport;

uses(ModuleAppTestCase::class);

beforeEach(function () {
    Config::set('streamer.outbox', true);
    Config::set('streamer.transport', 'recording');

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

    // The kernel's own infra migrations, on the iam module's database.
    Config::set('database.default', 'iam');
    foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') as $file) {
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

    $published = $this->app->make(Relay::class)->drain($this->app->make(ModuleRegistry::class)->get('iam'));

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
    ]);

    $this->artisan('modulith:events:publish --once --module=iam')->assertSuccessful();

    expect($this->app->make(RecordingTransport::class)->published)->toHaveCount(1)
        ->and(publications()->first()->published_at)->not->toBeNull();
});

it('stops the pass at the first failure so no later row overtakes it', function () {
    Config::set('streamer.transport', 'exploding');

    $this->app->make(Bus::class)->emit(new UserRegistered(1, 'first'));
    $this->app->make(Bus::class)->emit(new UserRegistered(2, 'second'));

    $published = $this->app->make(Relay::class)->drain($this->app->make(ModuleRegistry::class)->get('iam'));

    [$first, $second] = publications()->orderBy('sequence')->get()->all();

    expect($published)->toBe(0)
        ->and((int) $first->attempts)->toBe(1)
        ->and($first->last_error)->toContain('transport down')
        ->and((int) $second->attempts)->toBe(0);
});

it('requeues published rows to rebuild an emptied stream', function () {
    $this->app->make(Bus::class)->emit(new UserRegistered(5, 'lamp'));
    $this->artisan('modulith:events:publish --once --module=iam')->assertSuccessful();

    $this->artisan('modulith:events:republish --force --module=iam')->assertSuccessful();

    expect(publications()->first()->published_at)->toBeNull();

    $this->artisan('modulith:events:publish --once --module=iam')->assertSuccessful();

    expect($this->app->make(RecordingTransport::class)->published)->toHaveCount(2);
});
