<?php

declare(strict_types=1);

use Apps\Iam\Events\UserRegistered;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modulith\Contracts\Stream\Bus;
use Modulith\Contracts\Stream\Transport;
use Modulith\Data\Envelope;
use Modulith\Tests\Support\ModuleAppTestCase;
use Modulith\Transports\Stream\QueueTransport;

uses(ModuleAppTestCase::class);

beforeEach(function () {
    Config::set('queue.connections.modulith', [
        'driver' => 'database',
        'connection' => 'iam',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90,
    ]);
    Config::set('streamer.streams.default', ['driver' => 'queue', 'connection' => 'modulith']);

    Schema::connection('iam')->create('jobs', function (Blueprint $table): void {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
});

it('fans an envelope out to one queue per module with a database', function () {
    $this->app->make(Bus::class)->emit(new UserRegistered(5, 'ada'));

    expect(DB::connection('iam')->table('jobs')->orderBy('queue')->pluck('queue')->all())
        ->toBe(['modulith-default-analytics', 'modulith-default-iam']);
});

it('hands the consumer its own copy, then deletes it', function () {
    $this->app->make(Bus::class)->emit(new UserRegistered(5, 'ada'));

    /** @var QueueTransport $transport */
    $transport = $this->app->make(Transport::class);
    $received = [];

    $transport->consume('iam', ['iam'], function (Envelope $envelope) use (&$received, $transport): void {
        $received[] = $envelope->payload;
        $transport->stop();
    });

    expect($received)->toBe([['id' => 5, 'name' => 'ada']])
        ->and(DB::connection('iam')->table('jobs')->pluck('queue')->all())->toBe(['modulith-default-analytics']);
});
