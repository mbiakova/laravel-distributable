<?php

declare(strict_types=1);

use Apps\Iam\Jobs\SyncDirectory;
use Distributable\Jobs\FailedJobProvider;
use Distributable\Tests\Support\ModuleAppTestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(ModuleAppTestCase::class);

beforeEach(function () {
    foreach (['iam', 'analytics'] as $connection) {
        Schema::connection($connection)->create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        Schema::connection($connection)->create('job_batches', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });
    }
});

it('stores a failed job in the database of the module owning it', function () {
    $failer = app('queue.failer');
    $payload = json_encode(['uuid' => 'f-1', 'displayName' => SyncDirectory::class]);

    $failer->log('sync', 'default', $payload, new RuntimeException('boom'));

    expect($failer)->toBeInstanceOf(FailedJobProvider::class)
        ->and(DB::connection('iam')->table('failed_jobs')->count())->toBe(1)
        ->and(DB::connection('analytics')->table('failed_jobs')->count())->toBe(0)
        ->and($failer->find('f-1'))->not->toBeNull()
        ->and($failer->all())->toHaveCount(1);
});

it('stores a batch in the database of the module owning its jobs', function () {
    $batch = Bus::batch([new SyncDirectory])->dispatch();

    expect(DB::connection('iam')->table('job_batches')->where('id', $batch->id)->exists())->toBeTrue()
        ->and(DB::connection('analytics')->table('job_batches')->count())->toBe(0)
        ->and(Bus::findBatch($batch->id)?->finished())->toBeTrue();
});
