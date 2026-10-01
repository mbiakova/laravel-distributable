<?php

declare(strict_types=1);

namespace Modulith\Providers;

use Illuminate\Console\Command;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Modulith\Http\Middleware\SetModuleContext;
use Modulith\Traits\ResolvesModule;

/**
 * Base service provider a module extends to get, without manual wiring: config merging
 * (each {module}/config/*.php deep-merges into the matching root config),
 * translations ({module}/lang, namespaced by the module name),
 * routes ({module}/routes/{name}.php, prefixed {module}/{name}) and console commands.
 * Registered only for local modules (ModulithServiceProvider follows WITH_MODULES), so a
 * module's config lands only on the nodes that run it.
 */
abstract class ModuleServiceProvider extends BaseServiceProvider
{
    use ResolvesModule;

    /** @var array<class-string, class-string> the foundation contracts this module answers itself, when it runs here */
    protected array $services = [];

    public function register(): void
    {
        $this->mergeModuleConfigs();

        foreach ($this->services as $contract => $implementation) {
            $this->app->bind($contract, $implementation);
        }
    }

    public function boot(): void
    {
        $this->loadModuleRoutes();
        $this->loadModuleTranslations();
        $this->registerModuleCommands();
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
     * Merge a module's config fragment over the existing root config: associative keys recurse
     * (a module adds/overrides its own keys), list items append once (a handler several local
     * modules declare runs once per message).
     *
     * @param  array<array-key, mixed>  $base
     * @param  array<array-key, mixed>  $override
     * @return array<array-key, mixed>
     */
    private function deepMerge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_int($key)) {
                if (! in_array($value, $base, true)) {
                    $base[] = $value;
                }
            } elseif (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = $this->deepMerge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
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
