<?php

declare(strict_types=1);

use Apps\Analytics\Models\UserShadow;
use Apps\Iam\Models\User;
use Modulith\Services\Modules\CachedSource;
use Modulith\Services\Modules\DiscoveryCache;
use Modulith\Services\Modules\ManifestSource;
use Modulith\Tests\Support\ModuleAppTestCase;

uses(ModuleAppTestCase::class);

afterEach(function () {
    $this->app->make(DiscoveryCache::class)->clear();
});

it('caches what discovery finds, and reads it back as the same modules', function () {
    $this->artisan('modulith:cache')->assertSuccessful();

    $cached = $this->app->make(DiscoveryCache::class)->load();

    expect(is_file($this->app->make(DiscoveryCache::class)->path()))->toBeTrue()
        ->and($this->app->make(CachedSource::class)->modules())->toEqual($this->app->make(ManifestSource::class)->modules())
        ->and($cached['shadows']['analytics'])->toBe([UserShadow::class])
        ->and($cached['sources']['iam'])->toBe([User::class]);
});

it('removes the cache file, from its own command or optimize:clear', function () {
    $this->artisan('modulith:cache')->assertSuccessful();
    $this->artisan('modulith:clear')->assertSuccessful();

    expect(is_file($this->app->make(DiscoveryCache::class)->path()))->toBeFalse();

    $this->artisan('modulith:cache')->assertSuccessful();
    $this->artisan('optimize:clear')->assertSuccessful();

    expect(is_file($this->app->make(DiscoveryCache::class)->path()))->toBeFalse();
});
