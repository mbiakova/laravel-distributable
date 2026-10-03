<?php

declare(strict_types=1);

namespace Modulith\Providers;

use Composer\Autoload\ClassLoader;
use Illuminate\Bus\BatchFactory;
use Illuminate\Bus\BatchRepository as BatchRepositoryContract;
use Illuminate\Bus\DatabaseBatchRepository;
use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Concurrency\ProcessDriver;
use Illuminate\Console\Command;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Console\Migrations as Laravel;
use Illuminate\Database\Console\Seeds\SeedCommand;
use Illuminate\Database\DatabaseManager;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Laravel\Octane\Contracts\DispatchesTasks;
use Microservices\Contracts\Colocation;
use Microservices\Contracts\Rpc\RpcTransport;
use Microservices\Services\Shadows\ShadowRegistry;
use Modulith\Config\Modules;
use Modulith\Console\Commands\CacheModules;
use Modulith\Console\Commands\ClearModules;
use Modulith\Console\Commands\DeleteModule;
use Modulith\Console\Commands\Doctor;
use Modulith\Console\Commands\Install;
use Modulith\Console\Commands\ListModules;
use Modulith\Console\Commands\MakeModule;
use Modulith\Console\Commands\PurgeModules;
use Modulith\Console\Commands\UnusedPackages;
use Modulith\Console\Migrations;
use Modulith\Console\ModuleGenerators;
use Modulith\Console\ModuleOption;
use Modulith\Console\ModuleSeedCommand;
use Modulith\Data\Module;
use Modulith\Exceptions\ModuleException;
use Modulith\Http\Controllers\StatusController;
use Modulith\Jobs\BatchRepository;
use Modulith\Jobs\FailedJobProvider;
use Modulith\Services\Modules\CachedShadowRegistry;
use Modulith\Services\Modules\DiscoveryCache;
use Modulith\Services\Modules\ModuleColocation;
use Modulith\Services\Modules\ModuleContext;
use Modulith\Services\Modules\ModuleRegistry;
use Modulith\Services\Modules\ModuleRpcTransport;
use Modulith\Support\ModuleConcurrencyDriver;
use Modulith\Support\ModuleDeferredCallbacks;
use Modulith\Support\ModuleFactories;
use Modulith\Support\ModuleTaskDispatcher;

final class ModulithServiceProvider extends BaseServiceProvider
{
    private static bool $guardsRemoteModules = false;

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/modulith.php', 'modulith');

        // Left null, these database drivers would keep the connection of whichever module first used them.
        $config = $this->app['config'];

        foreach (['cache.stores.database.connection', 'queue.connections.database.connection', 'session.connection'] as $key) {
            $config->set($key, $config->get($key) ?? $config->get('database.default'));
        }

        $this->carryTheModuleIntoDeferredWork();

        // Every module is a service of laravel-microservices: its events, its RPC calls, its copies.
        $this->app->bind(Colocation::class, ModuleColocation::class);
        $this->app->bind(RpcTransport::class, ModuleRpcTransport::class);
        $this->app->singleton(ShadowRegistry::class, CachedShadowRegistry::class);

        $this->app->singleton(DiscoveryCache::class);

        // What the folder of a declared module tells is read from the cache when there is one.
        $this->app->singleton(ModuleRegistry::class, static function (Application $app): ModuleRegistry {
            $config = $app->make(Modules::class);
            $cached = array_column($app->make(DiscoveryCache::class)->load()['modules'] ?? [], null, 'name');

            return new ModuleRegistry(array_map(
                static fn (string $name): Module => isset($cached[$name])
                    ? Module::fromArray($cached[$name])
                    : Module::fromName($name, $config->getModulesNamespace(), $config->getModulesPath()),
                $config->getDeclaredModules(),
            ), $config->getLoadedModules());
        });

        $this->app->singleton(ModuleContext::class);
        $this->registerQueueDatabases();
        $this->registerModuleMigrations();
        ModuleFactories::register();

        $this->app->singleton(ModuleGenerators::class);
        $this->app->afterResolving(Command::class, ModuleOption::addTo(...));
        $this->app->extend(SeedCommand::class, static fn (mixed $command, Application $app): ModuleSeedCommand => new ModuleSeedCommand($app->make('db')));

        // On the booting callback: after every provider (and the app's own configuration)
        // has registered, before any provider boots — module providers slot in between.
        $this->app->booting(function (): void {
            $this->declareModulesAsServices();
            $this->registerLocalModuleProviders();
        });
    }

    /** microservices.services gets every module with its host, so a call to a module running elsewhere finds it. */
    private function declareModulesAsServices(): void
    {
        $config = $this->app['config'];

        $config->set('microservices.services', [...(array) $config->get('modulith.modules', []), ...(array) $config->get('microservices.services', [])]);
    }

    /** Work started in a module but run later or elsewhere (defer, Concurrency, Octane tasks) keeps that module. */
    private function carryTheModuleIntoDeferredWork(): void
    {
        $this->app->scoped(DeferredCallbackCollection::class, ModuleDeferredCallbacks::class);

        $this->app->afterResolving(ConcurrencyManager::class, static function (ConcurrencyManager $manager, Application $app): void {
            $manager->extend('process', fn (Application $app): ModuleConcurrencyDriver => new ModuleConcurrencyDriver(
                new ProcessDriver($app->make(ProcessFactory::class)),
                $app->make(ModuleContext::class),
            ));
        });

        if (interface_exists(DispatchesTasks::class)) {
            $this->app->bind(DispatchesTasks::class, ModuleTaskDispatcher::class);
        }
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

    /** A controller, job or command runs in the context of the module owning its class, batch counters included. */
    private function switchContextOnJobsAndCommands(): void
    {
        // Wherever the route is declared: a module's own file, routes/web.php or another package.
        Event::listen(RouteMatched::class, static function (RouteMatched $event): void {
            $app = Container::getInstance();
            $module = $app->make(ModuleRegistry::class)->forClass((string) $event->route->getControllerClass());

            if ($module !== null) {
                $app->make(ModuleContext::class)->switchTo($module);
            }
        });

        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            $command = $this->app->make(ConsoleKernel::class)->all()[$event->command] ?? null;

            if ($command !== null) {
                $this->app->make(ModuleContext::class)->switchToModuleOf($command::class);
            }

            // --module on any command (db:seed, tinker, model:show…) names the module it runs in.
            if (is_string($named = $event->input->getParameterOption('--module', null))) {
                $this->app->make(ModuleContext::class)->switchTo($this->app->make(ModuleRegistry::class)->get($named));
            }
        });

        Queue::before(function (JobProcessing $event): void {
            $context = $this->app->make(ModuleContext::class);

            // Laravel has just restored the Context the job was dispatched with, module included:
            // a job class outside every module (a queued closure) runs in the module that queued it.
            $context->switchTo($this->app->make(ModuleRegistry::class)->forClass($event->job->resolveName()) ?? $context->current());

            if ($this->app->resolved(BatchRepositoryContract::class)
                && ($repository = $this->app->make(BatchRepositoryContract::class)) instanceof BatchRepository) {
                $repository->useModuleOf($event->job->resolveName());
            }
        });
    }

    /** Registers the service provider of every module this process boots (MODULITH_RUNS). */
    private function registerLocalModuleProviders(): void
    {
        $registry = $this->app->make(ModuleRegistry::class);
        $this->autoloadModules($registry);

        foreach ($registry->local() as $module) {
            if (! is_dir($module->path())) {
                throw ModuleException::missingFolder($module->name, $module->path());
            }

            // class_exists keeps the app bootable while a module's provider does not exist yet.
            if (class_exists($module->provider)) {
                $this->app->register($module->provider);
            }
        }

        // After the modules: it binds a contract to its RpcService only where no local module answered.
        $foundation = $this->app->make(Modules::class)->getFoundationNamespace().'\\FoundationServiceProvider';

        if (class_exists($foundation)) {
            $this->app->register($foundation);
        }
    }

    /**
     * Autoloads the foundation and the modules this process runs, with no composer.json entry. A
     * class of a module running elsewhere is never loaded: using one throws instead of reading
     * code this process must not depend on — whether that code is still on disk or was purged.
     */
    private function autoloadModules(ModuleRegistry $registry): void
    {
        $loader = new ClassLoader;
        $config = $this->app->make(Modules::class);

        $loader->addPsr4($config->getFoundationNamespace().'\\', $config->getFoundationPath());

        foreach ($registry->local() as $module) {
            $loader->addPsr4($module->namespace.'\\', $module->classPath());
            $loader->addPsr4($module->namespace.'\\Database\\Factories\\', $module->path().'/database/factories');
            $loader->addPsr4($module->namespace.'\\Database\\Seeders\\', $module->path().'/database/seeders');
            $loader->addPsr4($module->namespace.'\\Tests\\', $module->path().'/tests');
        }

        $loader->register();

        if (! self::$guardsRemoteModules) {
            self::$guardsRemoteModules = true;

            // Prepended, so it answers before any loader that could still find the file; the registry is read per call.
            spl_autoload_register(static function (string $class): void {
                if (! app()->resolved(ModuleRegistry::class)) {
                    return;
                }

                $registry = app(ModuleRegistry::class);
                $module = $registry->forClass($class);

                if ($module !== null && ! $registry->isLocal($module->name)) {
                    throw ModuleException::notLocal($class, $module->name);
                }
            }, prepend: true);
        }
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../../config/modulith.php' => config_path('modulith.php'),
        ], 'modulith-config');

        $this->switchContextOnJobsAndCommands();

        $statusRoute = $this->app->make(Modules::class)->getStatusRoute();

        if ($statusRoute !== null) {
            Route::get($statusRoute, StatusController::class)->name('modulith.status');
        }

        $this->optimizes(optimize: 'modulith:cache', clear: 'modulith:clear', key: 'modulith');

        if ($this->app->runningInConsole()) {
            Event::listen(CommandStarting::class, fn (CommandStarting $event) => $this->app->make(ModuleGenerators::class)->starting($event));
            Event::listen(CommandFinished::class, fn (CommandFinished $event) => $this->app->make(ModuleGenerators::class)->finished($event));

            $this->commands([
                Install::class, MakeModule::class, DeleteModule::class, ListModules::class, Doctor::class, PurgeModules::class, UnusedPackages::class,
                CacheModules::class, ClearModules::class,
            ]);
        }
    }
}
