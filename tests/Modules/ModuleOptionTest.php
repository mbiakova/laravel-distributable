<?php

declare(strict_types=1);

use Distributable\Exceptions\ModuleException;
use Distributable\Services\Modules\ModuleContext;
use Distributable\Tests\Support\DefaultConnectionCommand;
use Distributable\Tests\Support\ModuleAppTestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(ModuleAppTestCase::class);

function iamUsersTable(string $connection): void
{
    Schema::connection($connection)->create('iam_users', function (Blueprint $table): void {
        $table->id();
        $table->string('name')->nullable();
        $table->timestamps();
    });
}

it('runs any command in the module --module names, and in no module without it', function () {
    Artisan::registerCommand($this->app->make(DefaultConnectionCommand::class));

    $this->artisan('probe:connection --module=iam')->expectsOutput('iam')->assertSuccessful();
    $this->artisan('probe:connection')->expectsOutput(ModuleContext::NO_MODULE)->assertSuccessful();
});

it('seeds a module with its own DatabaseSeeder, or the seeder of its own it is asked for', function () {
    iamUsersTable('iam');

    $this->artisan('db:seed --module=iam')->assertSuccessful();
    $this->artisan('db:seed --module=iam --class=AdminSeeder')->assertSuccessful();

    expect(DB::connection('iam')->table('iam_users')->pluck('name')->all())->toBe(['seeded', 'admin']);
});

it('has nothing to seed in a module without a DatabaseSeeder', function () {
    $this->artisan('db:seed --module=analytics')
        ->expectsOutputToContain('Module [analytics] has no DatabaseSeeder: nothing to seed.')
        ->assertSuccessful();
});

it('seeds each module with its own seeders when a migration run is asked to seed', function () {
    $this->artisan('migrate:fresh --seed --module=iam --module=analytics')->assertSuccessful();

    expect(DB::connection('iam_owner')->table('iam_users')->pluck('name')->all())->toBe(['seeded']);
});

it('refuses a module nobody declared', function () {
    $this->artisan('db:seed --module=ghost');
})->throws(ModuleException::class, 'Unknown module [ghost]');

it('offers --module on every command but make:provider', function () {
    $offers = fn (string $command): bool => Artisan::all()[$command]->getDefinition()->hasOption('module');

    expect($offers('db:seed'))->toBeTrue()
        ->and($offers('queue:work'))->toBeTrue()
        ->and($offers('migrate'))->toBeTrue()
        ->and($offers('make:provider'))->toBeFalse();
});
