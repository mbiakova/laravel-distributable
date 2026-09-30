<?php

declare(strict_types=1);

namespace Modulith\Providers;

use Composer\Autoload\ClassLoader;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Modulith\Config\Modules;
use Modulith\Config\Rpc;
use Modulith\Config\Streamer;
use Modulith\Console\Commands\AnnounceShadows;
use Modulith\Console\Commands\ConsumeEvents;
use Modulith\Console\Commands\Migrate;
use Modulith\Console\Commands\PublishEvents;
use Modulith\Console\Commands\RepublishEvents;
use Modulith\Console\Commands\TrimEvents;
use Modulith\Console\Commands\WantShadows;
use Modulith\Contracts\Bus;
use Modulith\Contracts\RpcTransport;
use Modulith\Contracts\Source;
use Modulith\Contracts\Transport;
use Modulith\Exceptions\ConfigurationException;
use Modulith\Http\Controllers\StatusController;
use Modulith\Http\Middleware\VerifyRpcSignature;
use Modulith\Services\Emitter;
use Modulith\Services\ManifestSource;
use Modulith\Services\ModuleRegistry;
use Modulith\Services\Outbox\Emitter as OutboxEmitter;
use Modulith\Services\TransportManager;
use Modulith\Transports\HttpRpcTransport;

final class ModulithServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/modulith.php', 'modulith');
        $this->mergeConfigFrom(__DIR__.'/../../config/streamer.php', 'streamer');
        $this->mergeConfigFrom(__DIR__.'/../../config/rpc.php', 'rpc');

        $this->app->singleton(TransportManager::class);
        $this->app->bind(RpcTransport::class, HttpRpcTransport::class);

        // The outbox is a behaviour layered over the transport, not a transport of its own.
        $this->app->singleton(Bus::class, static fn (Application $app): Bus => $app->make(
            $app->make(Streamer::class)->getOutbox() ? OutboxEmitter::class : Emitter::class,
        ));

        // Injecting the contract yields the configured transport; the manager stays the seam
        // where a consumer registers its own with extend().
        $this->app->bind(
            Transport::class,
            static fn (Application $app): Transport => $app->make(TransportManager::class)->driver(),
        );

        $this->app->singleton(ManifestSource::class, static function (Application $app): ManifestSource {
            $config = $app->make(Modules::class);

            return new ManifestSource(
                modulesPath: $config->getModulesPath(),
                modulesNamespace: $config->getModulesNamespace(),
            );
        });

        $this->app->singleton(ModuleRegistry::class, static function (Application $app): ModuleRegistry {
            $config = $app->make(Modules::class);
            $source = $app->make($config->getSource());

            if (! $source instanceof Source) {
                throw ConfigurationException::invalidSource($source::class, Source::class);
            }

            return new ModuleRegistry($source, $config->getLoadedModules());
        });

        // On the booting callback: after every provider (and the app's own configuration)
        // has registered, before any provider boots — module providers slot in between.
        $this->app->booting(function (): void {
            $this->registerLocalModuleProviders();
        });
    }

    /** Registers the service provider of every module this process boots (WITH_MODULES). */
    private function registerLocalModuleProviders(): void
    {
        $registry = $this->app->make(ModuleRegistry::class);
        $this->autoloadModules($registry);
        $this->bindModuleServices($registry);

        foreach ($registry->local() as $module) {
            // class_exists keeps the app bootable while a module's provider does not exist yet.
            if (class_exists($module->provider)) {
                $this->app->register($module->provider);
            }
        }
    }

    /** Maps each module's namespace to its app/ directory, so the application's composer.json needs no entry per module. */
    private function autoloadModules(ModuleRegistry $registry): void
    {
        $loader = new ClassLoader;

        foreach ($registry->all() as $module) {
            $loader->addPsr4($module->namespace.'\\', $module->classPath());
        }

        $loader->register();
    }

    /**
     * Binds each module contract to its local implementation when the module runs here, to its
     * remote one otherwise. Every module's config/rpc.php is read, loaded or not: a process must
     * know how to reach the modules it does not run.
     */
    private function bindModuleServices(ModuleRegistry $registry): void
    {
        $services = $this->app->make(Rpc::class)->getServices();

        foreach ($registry->all() as $module) {
            $file = $module->path().'/config/rpc.php';

            if (is_file($file)) {
                /** @var array{services?: array<class-string, array{module: string, local: class-string, remote: class-string}>} $declared */
                $declared = require $file;
                $services = [...$services, ...($declared['services'] ?? [])];
            }
        }

        foreach ($services as $contract => $service) {
            $this->app->bind($contract, $registry->isLocal($service['module']) ? $service['local'] : $service['remote']);
        }
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../../config/modulith.php' => config_path('modulith.php'),
            __DIR__.'/../../config/streamer.php' => config_path('streamer.php'),
            __DIR__.'/../../config/rpc.php' => config_path('rpc.php'),
        ], 'modulith-config');

        // Every module's routes/rpc.php lands in this group: nothing unsigned reaches it.
        $this->app->make(Router::class)->pushMiddlewareToGroup('rpc', VerifyRpcSignature::class);

        $statusRoute = $this->app->make(Modules::class)->getStatusRoute();

        if ($statusRoute !== null) {
            Route::get($statusRoute, StatusController::class)->name('modulith.status');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                Migrate::class, PublishEvents::class, RepublishEvents::class, ConsumeEvents::class,
                TrimEvents::class, AnnounceShadows::class, WantShadows::class,
            ]);
        }
    }
}
