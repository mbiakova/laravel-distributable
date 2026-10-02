<?php

declare(strict_types=1);

namespace Modulith\Providers;

use Illuminate\Console\Command;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Illuminate\Support\Str;
use Illuminate\View\Compilers\BladeCompiler;
use Modulith\Http\Middleware\SetModuleContext;
use Modulith\Services\Modules\ModuleContext;
use Modulith\Services\Rpc\LocalServices;
use Modulith\Traits\ResolvesModule;

/**
 * Base service provider a module extends to get, without manual wiring: config merging
 * (each {module}/config/*.php deep-merges into the matching root config),
 * translations ({module}/lang, namespaced by the module name),
 * routes ({module}/routes/{name}.php, prefixed {module}/{name}) and console commands.
 * Registered only for local modules (ModulithServiceProvider follows MODULITH_RUNS), so a
 * module's config lands only on the nodes that run it.
 */
abstract class ModuleServiceProvider extends BaseServiceProvider
{
    use ResolvesModule;

    /** @var array<class-string, class-string> each foundation contract this module implements, with its implementation */
    protected array $services = [];

    /** @var array<class-string, list<string>> as EventServiceProvider::$listen: event => listener classes, or Class@method */
    protected array $listen = [];

    public function register(): void
    {
        // A cached config already holds the merge, made while the .env was still loaded.
        if (! $this->app->configurationIsCached()) {
            $this->mergeModuleConfigs();
        }

        $local = $this->app->make(LocalServices::class);

        // A contract with an RpcService is rebound to it by the foundation provider: every call
        // then takes the same path, in this process or not. One without stays bound here.
        foreach ($this->services as $contract => $implementation) {
            $local->add($this->module, $contract, $implementation);
            $this->app->bind($contract, $implementation);
        }
    }

    public function boot(): void
    {
        $this->loadModuleRoutes();
        $this->loadModuleTranslations();
        $this->loadModuleViews();
        $this->registerModuleCommands();
        $this->registerModuleListeners();
    }

    /** Views, anonymous components and class components, all under the module name: view('iam::welcome'), <x-iam::alert />. */
    private function loadModuleViews(): void
    {
        $views = $this->module->path().'/resources/views';
        $module = $this->module;

        if (is_dir($views)) {
            $this->loadViewsFrom($views, $module->name);
        }

        $this->callAfterResolving(BladeCompiler::class, static function (BladeCompiler $blade) use ($views, $module): void {
            $blade->componentNamespace($module->namespace.'\\View\\Components', $module->name);

            if (is_dir($views.'/components')) {
                $blade->anonymousComponentPath($views.'/components', $module->name);
            }
        });
    }

    /** Each listener is built as Laravel builds it and runs in this module, an after-commit one once the transaction commits. */
    private function registerModuleListeners(): void
    {
        $module = $this->module;

        foreach ($this->listen as $event => $listeners) {
            foreach ($listeners as $listener) {
                $handle = Event::makeListener($listener);
                [$class] = Str::parseCallback($listener);
                $afterCommit = is_subclass_of($class, ShouldHandleEventsAfterCommit::class)
                    || (get_class_vars($class)['afterCommit'] ?? false) === true;

                Event::listen($event, static function (mixed ...$payload) use ($module, $handle, $event, $afterCommit): mixed {
                    $app = Container::getInstance();
                    $run = static fn (): mixed => $app->make(ModuleContext::class)->within($module, static fn (): mixed => $handle($event, $payload));

                    if ($afterCommit && $app->bound('db.transactions')) {
                        $app->make('db.transactions')->addCallback($run);

                        return null;
                    }

                    return $run();
                });
            }
        }
    }

    private function mergeModuleConfigs(): void
    {
        $configPath = $this->module->path().'/config';

        if (! is_dir($configPath)) {
            return;
        }

        foreach (glob($configPath.'/*.php') ?: [] as $configFile) {
            $key = pathinfo($configFile, PATHINFO_FILENAME);
            config()->set($key, $this->deepMerge(config($key, []), require $configFile));
        }
    }

    /**
     * A list gains the items it lacks; any other array merges key by key, integer keys included.
     *
     * @param  array<array-key, mixed>  $base
     * @param  array<array-key, mixed>  $override
     * @return array<array-key, mixed>
     */
    private function deepMerge(array $base, array $override): array
    {
        if (array_is_list($base) && array_is_list($override)) {
            foreach ($override as $value) {
                if (! in_array($value, $base, true)) {
                    $base[] = $value;
                }
            }

            return $base;
        }

        foreach ($override as $key => $value) {
            $base[$key] = is_array($value) && isset($base[$key]) && is_array($base[$key])
                ? $this->deepMerge($base[$key], $value)
                : $value;
        }

        return $base;
    }

    private function loadModuleRoutes(): void
    {
        $module = $this->module;
        $router = $this->app->make(Router::class);

        // routes/{name}.php → {module}/{name}/…, wrapped in the `{name}` middleware group when the
        // application defines one.
        foreach (glob($module->path().'/routes/*.php') ?: [] as $routeFile) {
            $name = basename($routeFile, '.php');
            $middleware = $router->hasMiddlewareGroup($name) ? [$name] : [];

            Route::prefix($module->name.'/'.$name)
                ->middleware([...$middleware, SetModuleContext::class.':'.$module->name])
                ->group($routeFile);
        }
    }

    private function loadModuleTranslations(): void
    {
        $langPath = $this->module->path().'/lang';

        if (is_dir($langPath)) {
            $this->loadTranslationsFrom($langPath, $this->module->name);
        }
    }

    private function registerModuleCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands(modulith_classes_with(Command::class, $this->module->classPath()));
    }
}
