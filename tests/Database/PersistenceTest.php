<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Iam\Actions\RegisterUser;
use Modules\Iam\Models\User;
use Modulith\Tests\Support\ModuleAppTestCase;

uses(ModuleAppTestCase::class);

beforeEach(function () {
    Schema::connection('iam')->create('iam_users', function (Blueprint $table) {
        $table->id();
        $table->string('name')->nullable();
        $table->timestamps();
    });
});

it('routes a module model to its module connection and prefixed table', function () {
    $user = new User;

    expect($user->getConnectionName())->toBe('iam')
        ->and($user->getTable())->toBe('iam_users');
});

it('writes through the module connection', function () {
    User::query()->create(['name' => 'written']);

    expect(DB::connection('iam')->table('iam_users')->where('name', 'written')->exists())
        ->toBeTrue();
});

it('transacts on the module connection', function () {
    $user = new RegisterUser()->execute('from-action');

    expect($user->exists)->toBeTrue()
        ->and(User::query()->where('name', 'from-action')->exists())->toBeTrue();
});
