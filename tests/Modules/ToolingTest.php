<?php

declare(strict_types=1);

use Distributable\Data\Module;
use Distributable\Services\Modules\ComposerAutoload;
use Distributable\Services\Modules\ModuleRegistry;
use Distributable\Tests\Support\ModuleAppTestCase;
use Illuminate\Support\Facades\File;

uses(ModuleAppTestCase::class);

it('installs the config, the modules directory and the foundation provider, then a module declares itself in that config', function () {
    $root = sys_get_temp_dir().'/distributable-install-'.uniqid();
    File::ensureDirectoryExists($root.'/config');
    $this->app->useConfigPath($root.'/config');
    config()->set('distributable.paths.modules', $root.'/apps');
    config()->set('distributable.paths.foundation', $root.'/foundation');

    try {
        $this->artisan('distributable:install')->assertSuccessful();

        expect(is_file($root.'/config/distributable.php'))->toBeTrue()
            ->and(is_dir($root.'/apps'))->toBeTrue()
            ->and(file_get_contents($root.'/foundation/FoundationServiceProvider.php'))
            ->toContain('namespace Foundation;')
            ->toContain('final class FoundationServiceProvider extends BaseServiceProvider');

        $this->artisan('distributable:make-module billing')->assertSuccessful();

        expect((require $root.'/config/distributable.php')['modules'])->toHaveKey('billing');
    } finally {
        File::deleteDirectory($root);
    }
});

it('creates a module, its provider and its foundation directory, and declares it', function () {
    $root = sys_get_temp_dir().'/distributable-make-'.uniqid();
    File::ensureDirectoryExists($root.'/config');
    File::copy(dirname(__DIR__, 2).'/config/distributable.php', $root.'/config/distributable.php');
    $this->app->useConfigPath($root.'/config');
    config()->set('distributable.paths.modules', $root.'/apps');
    config()->set('distributable.paths.foundation', $root.'/foundation');

    try {
        $this->artisan('distributable:make-module point_of_sale --database')->assertSuccessful();

        expect(file_get_contents($root.'/apps/PointOfSale/app/Providers/PointOfSaleServiceProvider.php'))
            ->toContain('namespace Apps\PointOfSale\Providers;')
            ->toContain('final class PointOfSaleServiceProvider extends ServiceProvider {}')
            ->and(is_file($root.'/apps/PointOfSale/routes/api.php'))->toBeTrue()
            ->and(file_get_contents($root.'/apps/PointOfSale/config/database.php'))->toContain("'point_of_sale_owner'")
            ->and(is_dir($root.'/foundation/PointOfSale/Contracts'))->toBeTrue()
            ->and((require $root.'/config/distributable.php')['modules'])->toBe(['point_of_sale' => []]);

        $this->artisan('distributable:make-module point_of_sale')->assertFailed();
    } finally {
        File::deleteDirectory($root);
    }
});

it('adds the module and the foundation to composer.json for the tools that read it, and tells an entry gone stale', function () {
    $root = sys_get_temp_dir().'/distributable-composer-'.uniqid();
    File::ensureDirectoryExists($root.'/config');
    File::copy(dirname(__DIR__, 2).'/config/distributable.php', $root.'/config/distributable.php');
    File::put($root.'/composer.json', '{"autoload": {"psr-4": {"App\\\\": "app/"}}, "extra": {}}');
    $this->app->setBasePath($root);
    $this->app->useConfigPath($root.'/config');
    config()->set('distributable.paths.modules', 'apps');
    config()->set('distributable.paths.foundation', 'foundation');

    try {
        $this->artisan('distributable:make-module billing')->expectsOutputToContain('Added to composer.json')->assertSuccessful();

        $composer = json_decode((string) file_get_contents($root.'/composer.json'), true);

        expect($composer['autoload']['psr-4'])->toBe([
            'App\\' => 'app/',
            'Foundation\\' => 'foundation/',
            'Apps\\Billing\\' => 'apps/Billing/app/',
            'Apps\\Billing\\Database\\Factories\\' => 'apps/Billing/database/factories/',
            'Apps\\Billing\\Database\\Seeders\\' => 'apps/Billing/database/seeders/',
        ])->and($composer['autoload-dev']['psr-4'])->toBe(['Apps\\Billing\\Tests\\' => 'apps/Billing/tests/'])
            ->and(file_get_contents($root.'/composer.json'))->toContain('"extra": {}');

        $autoload = $this->app->make(ComposerAutoload::class);

        expect($autoload->stale(Module::fromName('billing', 'Apps', 'apps')))->toBe([])
            ->and($autoload->stale(Module::fromName('billing', 'Apps', 'modules'))['Apps\\Billing\\'])
            ->toBe(['declared' => 'apps/Billing/app/', 'expected' => 'modules/Billing/app/']);
    } finally {
        File::deleteDirectory($root);
    }
});

it('deletes a module: its folder, its foundation folder, its declaration and its composer.json entries', function () {
    $root = sys_get_temp_dir().'/distributable-delete-'.uniqid();
    File::ensureDirectoryExists($root.'/config');
    File::copy(dirname(__DIR__, 2).'/config/distributable.php', $root.'/config/distributable.php');
    File::put($root.'/composer.json', '{"autoload": {"psr-4": {"App\\\\": "app/"}}}');
    $this->app->setBasePath($root);
    $this->app->useConfigPath($root.'/config');
    config()->set('distributable.paths.modules', 'apps');
    config()->set('distributable.paths.foundation', 'foundation');

    try {
        $this->artisan('distributable:make-module billing')->assertSuccessful();
        $this->artisan('distributable:make-module shipping')->assertSuccessful();
        config()->set('distributable.modules', ['billing' => [], 'shipping' => []]);
        $this->app->forgetInstance(ModuleRegistry::class);

        $this->artisan('distributable:delete-module billing --force')->assertSuccessful();

        $composer = json_decode((string) file_get_contents($root.'/composer.json'), true);

        expect(is_dir($root.'/apps/Billing'))->toBeFalse()
            ->and(is_dir($root.'/foundation/Billing'))->toBeFalse()
            ->and(is_dir($root.'/apps/Shipping'))->toBeTrue()
            ->and((require $root.'/config/distributable.php')['modules'])->toBe(['shipping' => []])
            ->and(array_keys($composer['autoload']['psr-4']))->toBe([
                'App\\', 'Foundation\\', 'Apps\\Shipping\\', 'Apps\\Shipping\\Database\\Factories\\', 'Apps\\Shipping\\Database\\Seeders\\',
            ])
            ->and(array_keys($composer['autoload-dev']['psr-4']))->toBe(['Apps\\Shipping\\Tests\\']);
    } finally {
        File::deleteDirectory($root);
    }
});

it('lists every module and where it runs', function () {
    config()->set('distributable.modules.iam', []);

    $this->artisan('distributable:list')
        ->expectsTable(['Module', 'Namespace', 'Runs here', 'Database', 'Remote host'], [
            ['analytics', 'Apps\Analytics', 'yes', 'analytics', '—'],
            ['gateway', 'Apps\Gateway', 'yes', '—', '—'],
            ['iam', 'Apps\Iam', 'yes', 'iam', '—'],
        ])
        ->assertSuccessful();
});

it('fails on a module whose provider does not exist, and names it', function () {
    config()->set('distributable.modules.ghost', []);
    $this->app->forgetInstance(ModuleRegistry::class);

    $this->artisan('distributable:doctor')
        ->expectsOutputToContain('[ghost] provider Apps\Ghost\Providers\GhostServiceProvider does not exist.')
        ->assertFailed();
});

it('fails on a module folder that distributable.modules does not declare', function () {
    config()->set('distributable.modules', ['analytics' => [], 'iam' => []]);
    $this->app->forgetInstance(ModuleRegistry::class);

    $this->artisan('distributable:doctor')
        ->expectsOutputToContain('/Gateway] is not declared in distributable.modules.')
        ->assertFailed();
});

it('fails when modules serve RPC contracts and no secret signs the calls', function () {
    config()->set('microservices.rpc.secret', '');

    $this->artisan('distributable:doctor')
        ->expectsOutputToContain('microservices.rpc.secret is empty')
        ->assertFailed();
});

it('fails on two modules setting one new config key to different values, and leaves lists and per-module values alone', function () {
    $file = dirname(__DIR__).'/Fixtures/apps/Analytics/config/iam.php';
    file_put_contents($file, "<?php\n\nreturn ['flag' => false, 'items' => ['from-analytics'], 'nested' => ['override' => 'analytics']];\n");

    try {
        $this->artisan('distributable:doctor')
            ->expectsOutputToContain('Modules [analytics, iam] set config [iam.flag] to different values')
            ->doesntExpectOutputToContain('[iam.items')
            ->doesntExpectOutputToContain('[iam.nested.override]')
            ->assertFailed();
    } finally {
        unlink($file);
    }
});

it('fails on a cache, queue or session kept in a database no module owns', function () {
    config()->set('queue.default', 'database');

    $this->artisan('distributable:doctor')
        ->expectsOutputToContain('[queue.default] is the database driver')
        ->assertFailed();
});

it('fails on a module with migrations and no database to run them in', function () {
    $migrations = dirname(__DIR__).'/Fixtures/apps/Gateway/database/migrations';
    mkdir($migrations, 0755, true);

    try {
        $this->artisan('distributable:doctor')
            ->expectsOutputToContain('[gateway] has migrations but no database')
            ->assertFailed();
    } finally {
        rmdir($migrations);
        rmdir(dirname($migrations));
    }
});

it('passes once every module can run', function () {
    $this->artisan('distributable:doctor')->expectsOutputToContain('Every module can run.')->assertSuccessful();
});
