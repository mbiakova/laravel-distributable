<?php

declare(strict_types=1);

namespace Distributable\Console;

use Closure;
use Distributable\Data\Module;
use Distributable\Services\Modules\ModuleRegistry;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Foundation\Application;
use Symfony\Component\Finder\Finder;

/** With --module, what a Laravel make:* command generates lands in that module, under its namespace. */
final class ModuleGenerators
{
    /** @var array{module: Module, app: string, database: string, config: string, views: mixed, namespace: string|null, files: list<string>}|null */
    private ?array $swapped = null;

    private int $nested = 0;

    public function __construct(
        private readonly Application $app,
        private readonly ModuleRegistry $registry,
    ) {}

    public function starting(CommandStarting $event): void
    {
        if ($this->swapped !== null) {
            $this->nested++;

            return;
        }

        $name = str_starts_with($event->command, 'make:') ? $event->input->getParameterOption('--module', null) : null;

        if (! is_string($name)) {
            return;
        }

        $module = $this->registry->get($name);

        // Laravel only uses Models/ when the directory exists, and a new module has none yet.
        if ($event->command === 'make:model' && ! is_dir($module->classPath().'/Models')) {
            mkdir($module->classPath().'/Models', 0755, true);
        }

        $this->swapped = [
            'module' => $module,
            'app' => $this->app->path(),
            'database' => $this->app->databasePath(),
            'config' => $this->app->configPath(),
            'views' => $this->app->make('config')->get('view.paths'),
            'namespace' => $this->namespace(),
            'files' => $this->files($module),
        ];

        $this->app->useAppPath($module->classPath());
        $this->app->useDatabasePath($module->path().'/database');
        $this->app->useConfigPath($module->path().'/config');
        $this->app->make('config')->set('view.paths', [$module->path().'/resources/views']);
        $this->namespace($module->namespace.'\\');
    }

    public function finished(CommandFinished $event): void
    {
        if ($this->swapped === null) {
            return;
        }

        if ($this->nested > 0) {
            $this->nested--;

            return;
        }

        ['module' => $module, 'files' => $before] = $this->swapped;

        $this->app->useAppPath($this->swapped['app']);
        $this->app->useDatabasePath($this->swapped['database']);
        $this->app->useConfigPath($this->swapped['config']);
        $this->app->make('config')->set('view.paths', $this->swapped['views']);
        $this->namespace($this->swapped['namespace']);
        $this->swapped = null;

        foreach ($this->fix($module, array_values(array_diff($this->files($module), $before))) as $moved) {
            $event->output->writeln("  Moved to [{$moved}].");
        }
    }

    /**
     * Laravel hard-codes the namespace of a factory, a seeder and a test, the folder of a test, and a view name without its namespace.
     *
     * @param  list<string>  $created
     * @return list<string> where each test was moved to
     */
    private function fix(Module $module, array $created): array
    {
        $views = $module->path().'/resources/views/';
        $names = [];
        $moved = [];

        foreach ($created as $file) {
            if (str_starts_with($file, $views) && str_ends_with($file, '.blade.php')) {
                $names[] = str_replace('/', '.', substr($file, strlen($views), -strlen('.blade.php')));
            }
        }

        foreach ($created as $file) {
            if (! str_ends_with($file, '.php') || str_starts_with($file, $views)) {
                continue;
            }

            $contents = (string) preg_replace(
                ['/^namespace Database\\\\(Factories|Seeders)/m', '/^namespace Tests\b/m'],
                ['namespace '.$module->namespace.'\\\\Database\\\\$1', 'namespace '.$module->namespace.'\\\\Tests'],
                (string) file_get_contents($file),
            );

            foreach ($names as $name) {
                $contents = str_replace("'{$name}'", "'{$module->name}::{$name}'", $contents);
            }

            $target = str_starts_with($file, $this->app->basePath('tests').'/')
                ? $module->path().'/tests/'.substr($file, strlen($this->app->basePath('tests').'/'))
                : $file;

            is_dir(dirname($target)) || mkdir(dirname($target), 0755, true);
            file_put_contents($target, $contents);

            if ($target !== $file) {
                unlink($file);
                $moved[] = $target;
            }
        }

        return $moved;
    }

    /** @return list<string> the files of the module, and of the application's tests/ where Laravel writes every test */
    private function files(Module $module): array
    {
        $directories = array_filter([$module->path(), $this->app->basePath('tests')], is_dir(...));

        return $directories === []
            ? []
            : array_map(strval(...), iterator_to_array(Finder::create()->files()->in($directories), false));
    }

    /** Reads the application namespace Laravel caches, or sets it when one is given: Laravel offers no setter. */
    private function namespace(?string ...$namespace): ?string
    {
        return Closure::bind(function () use ($namespace): ?string {
            return $namespace === [] ? $this->namespace : $this->namespace = $namespace[0];
        }, $this->app, Application::class)();
    }
}
