<?php

declare(strict_types=1);

namespace Distributable\Services\Modules;

use Closure;
use Distributable\Data\Module;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Context;

/**
 * Which module the running request, job or command belongs to; its connection becomes the
 * default one, so a plain DB::transaction() or query lands in that module's database.
 */
final class ModuleContext
{
    public const string CONTEXT_KEY = 'distributable.module';

    private ?string $applicationDefault = null;

    public function __construct(private readonly ModuleRegistry $registry) {}

    public function switchTo(?Module $module): void
    {
        // The running application, not the one this singleton was built in: Octane serves each
        // request from its own copy, with its own config.
        $config = Container::getInstance()->make(Repository::class);
        $this->applicationDefault ??= (string) $config->get('database.default');

        $config->set('database.default', $module !== null && $module->hasDatabase
            ? $module->connection()
            : $this->applicationDefault);

        $module !== null
            ? Context::addHidden(self::CONTEXT_KEY, $module->name)
            : Context::forgetHidden(self::CONTEXT_KEY);
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
        $previous = $this->current();
        $this->switchTo($module);

        try {
            return $callback();
        } finally {
            $this->switchTo($previous);
        }
    }

    public function current(): ?Module
    {
        $name = Context::getHidden(self::CONTEXT_KEY);

        return is_string($name) ? $this->registry->find($name) : null;
    }
}
