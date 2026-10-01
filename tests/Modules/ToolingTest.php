<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Modulith\Services\Modules\ModuleRegistry;
use Modulith\Tests\Support\ModuleAppTestCase;

uses(ModuleAppTestCase::class);

it('installs the config, the modules directory and the foundation provider, then a module declares itself in that config', function () {
    $root = sys_get_temp_dir().'/modulith-install-'.uniqid();
    File::ensureDirectoryExists($root.'/config');
    $this->app->useConfigPath($root.'/config');
    config()->set('modulith.paths.modules', $root.'/apps');
    config()->set('modulith.paths.foundation', $root.'/foundation');

    try {
        $this->artisan('modulith:install')->assertSuccessful();

        expect(is_file($root.'/config/modulith.php'))->toBeTrue()
            ->and(is_dir($root.'/apps'))->toBeTrue()
            ->and(file_get_contents($root.'/foundation/FoundationServiceProvider.php'))
            ->toContain('namespace Foundation;')
            ->toContain('final class FoundationServiceProvider extends BaseServiceProvider');

        $this->artisan('modulith:make-module billing')->assertSuccessful();

        expect((require $root.'/config/modulith.php')['modules'])->toHaveKey('billing');
    } finally {
        File::deleteDirectory($root);
    }
});

it('creates a module, its provider and its foundation directory, and declares it', function () {
    $root = sys_get_temp_dir().'/modulith-make-'.uniqid();
    File::ensureDirectoryExists($root.'/config');
    File::copy(dirname(__DIR__, 2).'/config/modulith.php', $root.'/config/modulith.php');
    $this->app->useConfigPath($root.'/config');
    config()->set('modulith.paths.modules', $root.'/apps');
    config()->set('modulith.paths.foundation', $root.'/foundation');

    try {
        $this->artisan('modulith:make-module point_of_sale --database')->assertSuccessful();

        expect(file_get_contents($root.'/apps/PointOfSale/app/Providers/PointOfSaleServiceProvider.php'))
            ->toContain('namespace Apps\PointOfSale\Providers;')
            ->toContain('final class PointOfSaleServiceProvider extends ModuleServiceProvider {}')
            ->and(is_file($root.'/apps/PointOfSale/routes/api.php'))->toBeTrue()
            ->and(file_get_contents($root.'/apps/PointOfSale/config/database.php'))->toContain("'point_of_sale_owner'")
            ->and(is_dir($root.'/foundation/PointOfSale/Contracts'))->toBeTrue()
            ->and((require $root.'/config/modulith.php')['modules'])->toBe(['point_of_sale' => []]);

        $this->artisan('modulith:make-module point_of_sale')->assertFailed();
    } finally {
        File::deleteDirectory($root);
    }
});

it('lists every module and where it runs', function () {
    config()->set('modulith.modules.iam', []);

    $this->artisan('modulith:list')
        ->expectsTable(['Module', 'Namespace', 'Runs here', 'Database', 'Remote host'], [
            ['analytics', 'Apps\Analytics', 'yes', 'analytics', '—'],
            ['gateway', 'Apps\Gateway', 'yes', '—', '—'],
            ['iam', 'Apps\Iam', 'yes', 'iam', '—'],
        ])
        ->assertSuccessful();
});

it('fails on a module whose provider does not exist, and names it', function () {
    config()->set('modulith.modules.ghost', []);
    $this->app->forgetInstance(ModuleRegistry::class);

    $this->artisan('modulith:doctor')
        ->expectsOutputToContain('[ghost] provider Apps\Ghost\Providers\GhostServiceProvider does not exist.')
        ->assertFailed();
});

it('fails on a module folder that modulith.modules does not declare', function () {
    config()->set('modulith.modules', ['analytics' => [], 'iam' => []]);
    $this->app->forgetInstance(ModuleRegistry::class);

    $this->artisan('modulith:doctor')
        ->expectsOutputToContain('/Gateway] is not declared in modulith.modules.')
        ->assertFailed();
});

it('fails when modules serve RPC contracts and no secret signs the calls', function () {
    config()->set('modulith.rpc.secret', '');

    $this->artisan('modulith:doctor')
        ->expectsOutputToContain('modulith.rpc.secret is empty')
        ->assertFailed();
});

it('falls back to the application key for the RPC secret', function () {
    putenv('MODULITH_RPC_SECRET');
    putenv('APP_KEY=app-key-value');

    try {
        expect((require dirname(__DIR__, 2).'/config/modulith.php')['rpc']['secret'])->toBe('app-key-value');
    } finally {
        putenv('APP_KEY');
    }
});

it('passes once every module can run', function () {
    $this->artisan('modulith:doctor')->expectsOutputToContain('Every module can run.')->assertSuccessful();
});
