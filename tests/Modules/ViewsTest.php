<?php

declare(strict_types=1);

use Distributable\Tests\Support\ModuleAppTestCase;
use Illuminate\Support\Facades\Blade;

uses(ModuleAppTestCase::class);

it('loads a module views under the module name', function () {
    expect(trim(view('iam::hello', ['name' => 'Ada'])->render()))->toBe('Hello Ada from iam');
});

it('renders a module anonymous component and its class component under the module name', function () {
    expect(trim(Blade::render('<x-iam::badge>new</x-iam::badge>')))->toBe('<span class="badge">new</span>')
        ->and(trim(Blade::render('<x-iam::alert />')))->toBe('<div class="alert">from the class</div>');
});

it('leaves a module without views alone', function () {
    expect(view()->exists('analytics::hello'))->toBeFalse();
});
