<?php

declare(strict_types=1);

namespace Modulith\Services\Modules;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Context;
use Modulith\Data\Module;

/**
 * Which module the running request, job or command belongs to; its connection becomes the
 * default one, so a plain DB::transaction() or query lands in that module's database.
 */
final class ModuleContext
{
    public const string CONTEXT_KEY = 'modulith.module';

    private ?string $applicationDefault = null;

    public function __construct(
        private readonly Repository $config,
        private readonly ModuleRegistry $registry,
    ) {}

    public function switchTo(?Module $module): void
    {
        $this->applicationDefault ??= (string) $this->config->get('database.default');

        $this->config->set('database.default', $module !== null && $module->hasDatabase
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
