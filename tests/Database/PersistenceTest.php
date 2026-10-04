<?php

declare(strict_types=1);

use Apps\Iam\Actions\RegisterUser;
use Apps\Iam\Models\User;
use Distributable\Exceptions\ModuleException;
use Distributable\Services\Modules\ModuleContext;
use Distributable\Tests\Support\ModuleAppTestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

it('leaves no default connection once the module code has run', function () {
    $this->inModule('iam', fn () => expect(DB::getDefaultConnection())->toBe('iam'));

    expect(DB::getDefaultConnection())->toBe(ModuleContext::NO_MODULE);
});

it('fails a query outside every module instead of running it on another database', function () {
    expect(fn () => User::query()->count())->toThrow(ModuleException::class, 'No module runs here');
});

it('leaves a connection set on a model alone, as an archive package sets its own', function () {
    User::on('iam')->create(['name' => 'set-by-hand']);

    expect(DB::connection('iam')->table('iam_users')->where('name', 'set-by-hand')->exists())->toBeTrue();
});
