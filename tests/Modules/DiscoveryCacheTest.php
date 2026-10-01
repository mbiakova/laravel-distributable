<?php

declare(strict_types=1);

use Apps\Analytics\Models\UserShadow;
use Apps\Iam\Models\User;
use Modulith\Services\Modules\DiscoveryCache;
use Modulith\Services\Modules\ModuleRegistry;
use Modulith\Tests\Support\ModuleAppTestCase;

uses(ModuleAppTestCase::class);

afterEach(function () {
    $this->app->make(DiscoveryCache::class)->clear();
});

it('caches what the module folders tell, and reads it back as the same modules', function () {
    $this->artisan('modulith:cache')->assertSuccessful();

    $cached = $this->app->make(DiscoveryCache::class)->load();
    $built = $this->app->make(ModuleRegistry::class)->all();
    $this->app->forgetInstance(ModuleRegistry::class);

    expect(is_file($this->app->make(DiscoveryCache::class)->path()))->toBeTrue()
        ->and($this->app->make(ModuleRegistry::class)->all())->toEqual($built)
        ->and($cached['shadows']['analytics'])->toBe([UserShadow::class])
        ->and($cached['sources']['iam'])->toBe([User::class]);
});

it('lists only the declared modules, even when the cache knew others', function () {
    $this->artisan('modulith:cache')->assertSuccessful();
    config()->set('modulith.modules', ['iam' => []]);
    $this->app->forgetInstance(ModuleRegistry::class);

    expect(array_map(fn ($module) => $module->name, $this->app->make(ModuleRegistry::class)->all()))->toBe(['iam']);
});

it('removes the cache file, from its own command or optimize:clear', function () {
    $this->artisan('modulith:cache')->assertSuccessful();
    $this->artisan('modulith:clear')->assertSuccessful();

    expect(is_file($this->app->make(DiscoveryCache::class)->path()))->toBeFalse();

    $this->artisan('modulith:cache')->assertSuccessful();
    $this->artisan('optimize:clear')->assertSuccessful();

    expect(is_file($this->app->make(DiscoveryCache::class)->path()))->toBeFalse();
});
