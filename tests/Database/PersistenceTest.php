<?php

declare(strict_types=1);

use Apps\Iam\Actions\RegisterUser;
use Apps\Iam\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modulith\Tests\Support\ModuleAppTestCase;

uses(ModuleAppTestCase::class);

beforeEach(function () {
    Schema::connection('iam')->create('iam_users', function (Blueprint $table) {
        $table->id();
        $table->string('name')->nullable();
        $table->timestamps();
    });
});

it('writes a plain Eloquent model to the database of the module it runs in', function () {
    $this->inModule('iam', fn () => User::query()->create(['name' => 'written']));

    expect(DB::connection('iam')->table('iam_users')->where('name', 'written')->exists())->toBeTrue();
});

it('transacts on the module connection with a plain DB::transaction() in the module context', function () {
    $user = $this->inModule('iam', fn (): User => new RegisterUser()->execute('from-action'));

    expect($user->exists)->toBeTrue()
        ->and(DB::connection('iam')->table('iam_users')->where('name', 'from-action')->exists())->toBeTrue();
});

it('puts the application default back once the module code has run', function () {
    $default = DB::getDefaultConnection();

    $this->inModule('iam', fn () => expect(DB::getDefaultConnection())->toBe('iam'));

    expect(DB::getDefaultConnection())->toBe($default);
});

it('keeps the database cache store on the application connection, whichever module uses it first', function () {
    $default = DB::getDefaultConnection();

    $connection = $this->inModule('iam', fn (): string => Cache::store('database')->getStore()->getConnection()->getName());

    expect($connection)->toBe($default);
});
