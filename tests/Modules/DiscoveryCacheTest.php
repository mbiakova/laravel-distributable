<?php

declare(strict_types=1);

use Apps\Analytics\Models\UserShadow;
use Apps\Iam\Models\User;
use Distributable\Services\Modules\DiscoveryCache;
use Distributable\Services\Modules\ModuleRegistry;
use Distributable\Tests\Support\ModuleAppTestCase;

uses(ModuleAppTestCase::class);

afterEach(function () {
    $this->app->make(DiscoveryCache::class)->clear();
});

it('caches what the module folders tell, and reads it back as the same modules', function () {
    $this->artisan('distributable:cache')->assertSuccessful();

    $cached = $this->app->make(DiscoveryCache::class)->load();
    $built = $this->app->make(ModuleRegistry::class)->all();
    $this->app->forgetInstance(ModuleRegistry::class);

    expect(is_file($this->app->make(DiscoveryCache::class)->path()))->toBeTrue()
        ->and($this->app->make(ModuleRegistry::class)->all())->toEqual($built)
        ->and($cached['shadows']['analytics'])->toBe([UserShadow::class])
        ->and($cached['sources']['iam'])->toBe([User::class]);
});

it('lists only the declared modules, even when the cache knew others', function () {
    $this->artisan('distributable:cache')->assertSuccessful();
    config()->set('distributable.modules', ['iam' => []]);
    $this->app->forgetInstance(ModuleRegistry::class);

    expect(array_map(fn ($module) => $module->name, $this->app->make(ModuleRegistry::class)->all()))->toBe(['iam']);
});

it('removes the cache file, from its own command or optimize:clear', function () {
    $this->artisan('distributable:cache')->assertSuccessful();
    $this->artisan('distributable:clear')->assertSuccessful();

    expect(is_file($this->app->make(DiscoveryCache::class)->path()))->toBeFalse();

    $this->artisan('distributable:cache')->assertSuccessful();
    $this->artisan('optimize:clear')->assertSuccessful();

    expect(is_file($this->app->make(DiscoveryCache::class)->path()))->toBeFalse();
});
