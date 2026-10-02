<?php

declare(strict_types=1);

use Apps\Iam\Database\Factories\UserFactory;
use Apps\Iam\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modulith\Support\ModuleFactories;
use Modulith\Tests\Support\ModuleAppTestCase;

uses(ModuleAppTestCase::class);

it('finds the factory of a module model in the module database/factories, and the model of that factory', function () {
    $user = User::factory()->make();

    expect(User::factory())->toBeInstanceOf(UserFactory::class)
        ->and($user)->toBeInstanceOf(User::class)
        ->and($user->name)->toBe('ada')
        ->and(UserFactory::new()->modelName())->toBe(User::class);
});

it('creates a module model through its factory in the module database', function () {
    Schema::connection('iam')->create('iam_users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    $user = $this->inModuleOf(User::class, fn (): User => User::factory()->create());

    expect($user->getConnectionName())->toBe('iam')
        ->and(DB::connection('iam')->table('iam_users')->value('name'))->toBe('ada');
});

it('keeps Laravel rule for every class outside a module', function () {
    expect(ModuleFactories::factoryName('App\Models\Post'))->toBe('Database\Factories\PostFactory')
        ->and(ModuleFactories::factoryName('App\Billing\Invoice'))->toBe('Database\Factories\Billing\InvoiceFactory')
        ->and(Factory::resolveFactoryName('App\Models\Post'))->toBe('Database\Factories\PostFactory');
});
