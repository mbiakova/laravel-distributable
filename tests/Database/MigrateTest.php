<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modulith\Tests\Support\ModuleAppTestCase;

uses(ModuleAppTestCase::class);

it('migrates the application database, then each local module database on its owner connection', function () {
    $this->artisan('migrate')->assertSuccessful();

    expect(Schema::connection('iam_owner')->hasTable('iam_users'))->toBeTrue()
        ->and(Schema::connection('iam_owner')->hasTable('event_publications'))->toBeTrue()
        ->and(Schema::connection('iam_owner')->hasTable('event_consumptions'))->toBeTrue()
        ->and(Schema::connection('iam_owner')->hasTable('migrations'))->toBeTrue()
        ->and(Schema::hasTable('migrations'))->toBeTrue()
        ->and(Schema::hasTable('iam_users'))->toBeFalse();
});

it('runs the migrations a third-party package loads, in the application database and in each module database', function () {
    app('migrator')->path(dirname(__DIR__).'/Fixtures/packages/acme/migrations');

    $this->artisan('migrate')->assertSuccessful();

    expect(Schema::hasTable('acme_things'))->toBeTrue()
        ->and(Schema::connection('iam_owner')->hasTable('acme_things'))->toBeTrue();
});

it('limits a run to the modules named, and leaves the application database alone', function () {
    $this->artisan('migrate --module=iam')->assertSuccessful();

    expect(Schema::connection('iam_owner')->hasTable('iam_users'))->toBeTrue()
        ->and(Schema::hasTable('migrations'))->toBeFalse();
});

it('rolls a module database back with the plain Laravel command', function () {
    $this->artisan('migrate')->assertSuccessful();
    $this->artisan('migrate:rollback --module=iam')->assertSuccessful();

    expect(Schema::connection('iam_owner')->hasTable('iam_users'))->toBeFalse()
        ->and(DB::connection('iam_owner')->table('migrations')->count())->toBe(0);
});

it('wipes and rebuilds a module database with migrate:fresh', function () {
    $this->artisan('migrate')->assertSuccessful();
    DB::connection('iam_owner')->table('iam_users')->insert(['name' => 'gone']);

    $this->artisan('migrate:fresh --module=iam')->assertSuccessful();

    expect(Schema::connection('iam_owner')->hasTable('iam_users'))->toBeTrue()
        ->and(DB::connection('iam_owner')->table('iam_users')->count())->toBe(0);
});

it('reports each database from migrate:status', function () {
    $this->artisan('migrate')->assertSuccessful();

    $this->artisan('migrate:status')->expectsOutputToContain('Module [iam]')->assertSuccessful();
});

it('keeps an explicit --database the plain Laravel command', function () {
    $this->artisan('migrate --database=iam_owner --path='.dirname(__DIR__).'/Fixtures/apps/Iam/database/migrations --realpath')->assertSuccessful();

    expect(Schema::connection('iam_owner')->hasTable('iam_users'))->toBeTrue()
        ->and(Schema::connection('iam_owner')->hasTable('event_publications'))->toBeFalse();
});
