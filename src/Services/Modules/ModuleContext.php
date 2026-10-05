<?php

declare(strict_types=1);

namespace Distributable\Services\Modules;

use ArrayObject;
use Closure;
use Distributable\Config\Modules;
use Distributable\Data\Module;
use Distributable\Exceptions\ModuleException;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Facade;

/**
 * Which module the running request, job or command belongs to; its connection becomes the
 * default one, so a plain DB::transaction() or query lands in that module's database. Outside a
 * module with a database the default is NO_MODULE, whose every query fails.
 */
final class ModuleContext
{
    public const string CONTEXT_KEY = 'distributable.module';

    public const string NO_MODULE = 'no_module';

    private const string SERVICES = 'distributable.module_services';

    public function __construct(private readonly ModuleRegistry $registry) {}

    public function switchTo(?Module $module): void
    {
        // The running application, not the one this singleton was built in: Octane serves each
        // request from its own copy, with its own config.
        $config = Container::getInstance()->make(Repository::class);

        $config->set('database.default', $module !== null && $module->hasDatabase
            ? $module->connection()
            : self::NO_MODULE);

        // The application's values first, then the ones this module sets for itself (ServiceProvider).
        $config->set(Arr::dot((array) $config->get('distributable.overlay_base', [])));

        if ($module !== null) {
            $config->set(Arr::dot((array) $config->get("distributable.overlays.{$module->name}", [])));
        }

        $this->swapServices($module, (array) $config->get('distributable.per_module', []));

        $module !== null
            ? Context::addHidden(self::CONTEXT_KEY, $module->name)
            : Context::forgetHidden(self::CONTEXT_KEY);
    }

    /**
     * Each service is built once per module, as make() builds it (deferred provider, extenders) with the
     * config just applied, then kept in the running application: an Octane copy builds its own.
     *
     * @param  array<array-key, mixed>  $services
     */
    private function swapServices(?Module $module, array $services): void
    {
        if ($services === []) {
            return;
        }

        $app = Application::getInstance();

        if (! $app->bound(self::SERVICES)) {
            $app->instance(self::SERVICES, new ArrayObject);
        }

        /** @var ArrayObject<string, array<string, mixed>> $built */
        $built = $app->make(self::SERVICES);
        $owner = $module->name ?? '';

        foreach ($services as $id) {
            if (! is_string($id) || ! ($app->isDeferredService($id) || isset($app->getBindings()[$id]))) {
                continue;
            }

            $instances = $built[$owner] ?? [];

            if (! isset($instances[$id])) {
                $app->forgetInstance($id);
                $instances[$id] = $app->make($id);
            }

            $built[$owner] = $instances;

            $app->instance($id, $instances[$id]);
            Facade::clearResolvedInstance($id);
        }
    }

    public function switchToModuleOf(string $class): void
    {
        $this->switchTo($this->registry->forClass($class));
    }

    /**
     * Wraps $task so it runs in the current module wherever it runs later: after the response, in
     * another worker, in a child process. Only the module's name is captured, so the task stays
     * serializable.
     *
     * @param  Closure(): mixed  $task
     * @return Closure(): mixed
     */
    public function bind(Closure $task): Closure
    {
        $module = $this->current()?->name;

        return static function () use ($module, $task): mixed {
            $context = Container::getInstance()->make(self::class);

            return $context->within($module === null ? null : $context->registry->find($module), $task);
        };
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function within(?Module $module, Closure $callback): mixed
    {
        if ($module !== null) {
            $this->refuseAnotherModuleCaller($module);
        }

        $previous = $this->current();
        $this->switchTo($module);

        try {
            return $callback();
        } finally {
            $this->switchTo($previous);
        }
    }

    /**
     * The code that asks for $module, past the package's relays, is the package, a test or $module's own:
     * a module or the foundation entering another module would read its database behind its contract.
     */
    private function refuseAnotherModuleCaller(Module $module): void
    {
        $relays = [dirname(__DIR__).'/Modules/ModuleColocation.php', dirname(__DIR__, 2).'/Testing/InteractsWithModules.php'];

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $file = $frame['file'] ?? null;

            if ($file === null || $file === __FILE__ || in_array($file, $relays, true)) {
                continue;
            }

            foreach ($this->registry->all() as $owner) {
                if ($owner->name !== $module->name && str_starts_with($file, $owner->classPath().DIRECTORY_SEPARATOR)) {
                    throw ModuleException::entersAnotherModule($owner->name, $module->name, $file);
                }
            }

            if (str_starts_with($file, Container::getInstance()->make(Modules::class)->getFoundationPath().DIRECTORY_SEPARATOR)) {
                throw ModuleException::entersAnotherModule('foundation', $module->name, $file);
            }

            return;
        }
    }

    public function current(): ?Module
    {
        $name = Context::getHidden(self::CONTEXT_KEY);

        return is_string($name) ? $this->registry->find($name) : null;
    }
}
