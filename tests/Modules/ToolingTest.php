<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Modulith\Contracts\Modules\Source;
use Modulith\Data\Module;
use Modulith\Services\Modules\ManifestSource;
use Modulith\Services\Modules\ModuleRegistry;
use Modulith\Tests\Support\ModuleAppTestCase;

uses(ModuleAppTestCase::class);

it('creates a module, its provider and its foundation directory', function () {
    $root = sys_get_temp_dir().'/modulith-make-'.uniqid();
    config()->set('modulith.modules_path', $root.'/apps');
    config()->set('modulith.foundation_path', $root.'/foundation');

    try {
        $this->artisan('modulith:make-module point_of_sale --database')->assertSuccessful();

        expect(file_get_contents($root.'/apps/PointOfSale/app/Providers/PointOfSaleServiceProvider.php'))
            ->toContain('namespace Apps\PointOfSale\Providers;')
            ->toContain('final class PointOfSaleServiceProvider extends ModuleServiceProvider {}')
            ->and(is_file($root.'/apps/PointOfSale/modulith.php'))->toBeTrue()
            ->and(is_file($root.'/apps/PointOfSale/routes/api.php'))->toBeTrue()
            ->and(file_get_contents($root.'/apps/PointOfSale/config/database.php'))->toContain("'point_of_sale_owner'")
            ->and(is_dir($root.'/foundation/PointOfSale/Contracts'))->toBeTrue();

        $this->artisan('modulith:make-module point_of_sale')->assertFailed();
    } finally {
        File::deleteDirectory($root);
    }
});

it('lists every module and where it runs', function () {
    $this->artisan('modulith:list')
        ->expectsTable(['Module', 'Namespace', 'Runs here', 'Database', 'Remote host'], [
            ['analytics', 'Apps\Analytics', 'yes', 'analytics', '—'],
            ['gateway', 'Apps\Gateway', 'yes', '—', '—'],
            ['iam', 'Apps\Iam', 'yes', 'iam', '—'],
        ])
        ->assertSuccessful();
});

it('fails on a module whose provider does not exist, and names it', function () {
    $this->artisan('modulith:doctor')
        ->expectsOutputToContain('[gateway] provider Apps\Gateway\Providers\GatewayServiceProvider does not exist.')
        ->assertFailed();
});

it('fails when modules serve RPC contracts and no secret signs the calls', function () {
    config()->set('rpc.secret', '');

    $this->artisan('modulith:doctor')
        ->expectsOutputToContain('rpc.secret is empty')
        ->assertFailed();
});

it('falls back to the application key for the RPC secret', function () {
    putenv('MODULITH_RPC_SECRET');
    putenv('APP_KEY=app-key-value');

    try {
        expect((require dirname(__DIR__, 2).'/config/rpc.php')['secret'])->toBe('app-key-value');
    } finally {
        putenv('APP_KEY');
    }
});

it('passes once every module can run', function () {
    $withoutGateway = new class($this->app->make(ManifestSource::class)) implements Source
    {
        public function __construct(private readonly Source $source) {}

        public function modules(): array
        {
            return array_values(array_filter($this->source->modules(), fn (Module $module): bool => $module->name !== 'gateway'));
        }
    };
    $this->app->instance(ModuleRegistry::class, new ModuleRegistry($withoutGateway));

    $this->artisan('modulith:doctor')->expectsOutputToContain('Every module can run.')->assertSuccessful();
});
