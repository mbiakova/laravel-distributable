<?php

declare(strict_types=1);

namespace Modulith\Providers;

use Composer\Autoload\ClassLoader;
use Illuminate\Bus\BatchFactory;
use Illuminate\Bus\BatchRepository as BatchRepositoryContract;
use Illuminate\Bus\DatabaseBatchRepository;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Console\Migrations as Laravel;
use Illuminate\Database\DatabaseManager;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Modulith\Config\Modules;
use Modulith\Config\Rpc;
use Modulith\Console\Commands\AnnounceShadows;
use Modulith\Console\Commands\CacheModules;
use Modulith\Console\Commands\ClearModules;
use Modulith\Console\Commands\ConsumeEvents;
use Modulith\Console\Commands\Doctor;
use Modulith\Console\Commands\ExportEvents;
use Modulith\Console\Commands\ImportEvents;
use Modulith\Console\Commands\ListModules;
use Modulith\Console\Commands\MakeModule;
use Modulith\Console\Commands\PublishEvents;
use Modulith\Console\Commands\RepublishEvents;
use Modulith\Console\Commands\TrimEvents;
use Modulith\Console\Commands\WantShadows;
use Modulith\Console\Migrations;
use Modulith\Contracts\Modules\Source;
use Modulith\Contracts\Rpc\RpcTransport;
use Modulith\Contracts\Stream\Bus;
use Modulith\Contracts\Stream\Transport;
use Modulith\Exceptions\ConfigurationException;
use Modulith\Http\Controllers\StatusController;
use Modulith\Http\Middleware\VerifyRpcSignature;
use Modulith\Jobs\BatchRepository;
use Modulith\Jobs\FailedJobProvider;
use Modulith\Services\Modules\CachedSource;
use Modulith\Services\Modules\DiscoveryCache;
use Modulith\Services\Modules\ManifestSource;
use Modulith\Services\Modules\ModuleContext;
use Modulith\Services\Modules\ModuleRegistry;
use Modulith\Services\Rpc\RpcTransportManager;
use Modulith\Services\Stream\Emitter;
use Modulith\Services\Stream\TransportManager;

final class ModulithServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/modulith.php', 'modulith');
        $this->mergeConfigFrom(__DIR__.'/../../config/streamer.php', 'streamer');
        $this->mergeConfigFrom(__DIR__.'/../../config/rpc.php', 'rpc');

        $this->app->singleton(TransportManager::class);
        $this->app->singleton(RpcTransportManager::class);
        $this->app->bind(RpcTransport::class, RpcTransportManager::class);

        $this->app->singleton(Bus::class, Emitter::class);

        // Injecting the contract yields the default stream; the manager stays the seam
        // where a consumer registers its own driver with extend().
        $this->app->bind(
            Transport::class,
            static fn (Application $app): Transport => $app->make(TransportManager::class)->stream(),
        );

        $this->app->singleton(ManifestSource::class, static function (Application $app): ManifestSource {
            $config = $app->make(Modules::class);

            return new ManifestSource(
                modulesPath: $config->getModulesPath(),
                modulesNamespace: $config->getModulesNamespace(),
            );
        });

        $this->app->singleton(DiscoveryCache::class);

        $this->app->singleton(ModuleRegistry::class, static function (Application $app): ModuleRegistry {
            $config = $app->make(Modules::class);
            $source = $app->make(DiscoveryCache::class)->load() !== null
                ? $app->make(CachedSource::class)
                : $app->make($config->getSource());

            if (! $source instanceof Source) {
                throw ConfigurationException::invalidSource($source::class, Source::class);
            }

            return new ModuleRegistry($source, $config->getLoadedModules());
        });

        $this->app->singleton(ModuleContext::class);
        $this->registerQueueDatabases();
        $this->registerModuleMigrations();

        // On the booting callback: after every provider (and the app's own configuration)
        // has registered, before any provider boots — module providers slot in between.
        $this->app->booting(function (): void {
            $this->registerLocalModuleProviders();
        });
    }

    /** Laravel's own migrate:* commands, run once per database: the application's, then each local module's. */
    private function registerModuleMigrations(): void
    {
        $this->app->extend(Laravel\MigrateCommand::class, static fn (mixed $command, Application $app): Migrations\MigrateCommand => new Migrations\MigrateCommand($app['migrator'], $app[Dispatcher::class]));
        $this->app->extend(Laravel\StatusCommand::class, static fn (mixed $command, Application $app): Migrations\StatusCommand => new Migrations\StatusCommand($app['migrator']));
        $this->app->extend(Laravel\RollbackCommand::class, static fn (mixed $command, Application $app): Migrations\RollbackCommand => new Migrations\RollbackCommand($app['migrator']));
        $this->app->extend(Laravel\ResetCommand::class, static fn (mixed $command, Application $app): Migrations\ResetCommand => new Migrations\ResetCommand($app['migrator']));
        $this->app->extend(Laravel\FreshCommand::class, static fn (mixed $command, Application $app): Migrations\FreshCommand => new Migrations\FreshCommand($app['migrator']));
        $this->app->extend(Laravel\RefreshCommand::class, static fn (): Migrations\RefreshCommand => new Migrations\RefreshCommand);
    }

    /** Failed jobs and batches live in the database of the module owning the job, when Laravel stores them in a database. */
    private function registerQueueDatabases(): void
    {
        $this->app->extend('queue.failer', static fn (mixed $failer, Application $app): mixed => config('queue.failed.driver') === 'database-uuids'
            ? new FailedJobProvider(
                $app->make('db'),
                (string) config('queue.failed.database', ''),
                (string) config('queue.failed.table', 'failed_jobs'),
                $app->make(ModuleRegistry::class),
            )
            : $failer);

        $this->app->singleton(BatchRepository::class, static fn (Application $app): BatchRepository => new BatchRepository(
            $app->make(BatchFactory::class),
            $app->make(DatabaseManager::class),
            (string) config('queue.batching.database', ''),
            (string) config('queue.batching.table', 'job_batches'),
            $app->make(ModuleRegistry::class),
        ));

        $this->app->extend(BatchRepositoryContract::class, static fn (mixed $repository, Application $app): mixed => $repository instanceof DatabaseBatchRepository
            ? $app->make(BatchRepository::class)
            : $repository);
    }

    /** A job or command runs in the context of the module owning its class, batch counters included. */
    private function switchContextOnJobsAndCommands(): void
    {
        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            $command = $this->app->make(ConsoleKernel::class)->all()[$event->command] ?? null;

            if ($command !== null) {
                $this->app->make(ModuleContext::class)->switchToModuleOf($command::class);
            }
        });

        Queue::before(function (JobProcessing $event): void {
            $this->app->make(ModuleContext::class)->switchToModuleOf($event->job->resolveName());

            if ($this->app->resolved(BatchRepositoryContract::class)
                && ($repository = $this->app->make(BatchRepositoryContract::class)) instanceof BatchRepository) {
                $repository->useModuleOf($event->job->resolveName());
            }
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

    /** Maps each module's namespace to its app/ directory, and the foundation's to its own, so composer.json needs no entry. */
    private function autoloadModules(ModuleRegistry $registry): void
    {
        $loader = new ClassLoader;
        $config = $this->app->make(Modules::class);

        $loader->addPsr4($config->getFoundationNamespace().'\\', $config->getFoundationPath());

        foreach ($registry->all() as $module) {
            $loader->addPsr4($module->namespace.'\\', $module->classPath());
        }

        $loader->register();
    }

    /**
     * Binds each contract a module's foundation/{Module}/rpc.php declares: to the module's own
     * {Module}\Services\{Contract} when it runs here, to the declared RpcService otherwise.
     */
    private function bindModuleServices(ModuleRegistry $registry): void
    {
        $services = $this->app->make(Rpc::class)->getServices();
        $foundation = $this->app->make(Modules::class)->getFoundationPath();

        foreach ($registry->all() as $module) {
            $file = $foundation.'/'.basename($module->path()).'/rpc.php';

            if (is_file($file)) {
                /** @var array<class-string, class-string> $declared */
                $declared = require $file;

                foreach ($declared as $contract => $rpc) {
                    $services[$contract] = ['module' => $module->name, 'rpc' => $rpc];
                }
            }
        }

        // Every module's contracts, running here or not: what modulith:doctor and the app read back.
        $this->app['config']->set('rpc.services', $services);

        foreach ($services as $contract => $service) {
            $local = $registry->get($service['module'])->namespace.'\\Services\\'.class_basename($contract);

            $this->app->bind($contract, $registry->isLocal($service['module']) && class_exists($local) ? $local : $service['rpc']);
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
        $this->switchContextOnJobsAndCommands();

        $statusRoute = $this->app->make(Modules::class)->getStatusRoute();

        if ($statusRoute !== null) {
            Route::get($statusRoute, StatusController::class)->name('modulith.status');
        }

        $this->optimizes(optimize: 'modulith:cache', clear: 'modulith:clear', key: 'modulith');

        if ($this->app->runningInConsole()) {
            $this->commands([
                MakeModule::class, ListModules::class, Doctor::class,
                CacheModules::class, ClearModules::class, PublishEvents::class, RepublishEvents::class, ConsumeEvents::class,
                TrimEvents::class, ExportEvents::class, ImportEvents::class, AnnounceShadows::class, WantShadows::class,
            ]);
        }
    }
}
