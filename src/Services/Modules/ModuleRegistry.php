<?php

declare(strict_types=1);

namespace Modulith\Services\Modules;

use Illuminate\Support\Str;
use Modulith\Contracts\Modules\Source;
use Modulith\Data\Module;
use Modulith\Exceptions\ModuleException;

/**
 * The process-level view of the modules: every module the source declares, and the ones this
 * process loads (WITH_MODULES). Being loaded is a fact about the process, never about a module.
 */
final class ModuleRegistry
{
    /** @var array<string, Module>|null */
    private ?array $modules = null;

    /** @param list<string> $loadedModules ['*'] for every module, or their names */
    public function __construct(
        private readonly Source $source,
        private readonly array $loadedModules = ['*'],
    ) {}

    /** @return list<Module> */
    public function all(): array
    {
        return array_values($this->modules());
    }

    /** @return list<Module> The modules this process loads. */
    public function local(): array
    {
        return $this->loadsEveryModule()
            ? $this->all()
            : array_map($this->get(...), $this->loadedModules);
    }

    public function get(string $name): Module
    {
        return $this->find($name) ?? throw ModuleException::notFound($name, array_keys($this->modules()));
    }

    public function find(string $name): ?Module
    {
        return $this->modules()[$name] ?? null;
    }

    /** The module owning a class, resolved from its namespace; null outside any module. */
    public function forClass(string $class): ?Module
    {
        $best = null;

        foreach ($this->modules() as $module) {
            if (str_starts_with($class, $module->namespace.'\\')
                && ($best === null || strlen($module->namespace) > strlen($best->namespace))) {
                $best = $module;
            }
        }

        return $best;
    }

    /** The module a foundation class belongs to: Foundation\Iam\… is iam's; null outside the foundation. */
    public function forFoundationClass(string $class, string $foundationNamespace): ?Module
    {
        if (! str_starts_with($class, $foundationNamespace.'\\')) {
            return null;
        }

        return $this->find(Str::snake(Str::before(Str::after($class, $foundationNamespace.'\\'), '\\')));
    }

    public function isLocal(string $name): bool
    {
        $this->get($name); // an unknown name fails loudly rather than reading as "remote"

        return $this->loadsEveryModule() || in_array($name, $this->loadedModules, true);
    }

    private function loadsEveryModule(): bool
    {
        return $this->loadedModules === ['*'];
    }

    /** @return array<string, Module> */
    private function modules(): array
    {
        if ($this->modules !== null) {
            return $this->modules;
        }

        $keyed = [];

        foreach ($this->source->modules() as $module) {
            if (isset($keyed[$module->name])) {
                throw ModuleException::duplicateName($module->name);
            }

            $keyed[$module->name] = $module;
        }

        return $this->modules = $keyed;
    }
}
