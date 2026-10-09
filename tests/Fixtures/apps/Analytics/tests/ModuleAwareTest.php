<?php

declare(strict_types=1);

use Distributable\Tests\Support\ModuleAppTestCase;
use Illuminate\Support\Facades\DB;

uses(ModuleAppTestCase::class);

it('runs a test written in a module folder in that module', function () {
    expect(DB::getDefaultConnection())->toBe('analytics');
});

it('is still in its module after a request to another module', function () {
    $this->get('/iam/api/v1/connection')->assertOk();

    expect(DB::getDefaultConnection())->toBe('analytics');
});

it('is still in its module after a command of another module', function () {
    $this->artisan('iam:connection')->assertSuccessful();

    expect(DB::getDefaultConnection())->toBe('analytics');
});
