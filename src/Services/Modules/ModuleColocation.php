<?php

declare(strict_types=1);

namespace Modulith\Services\Modules;

use Closure;
use Microservices\Contracts\Colocation;
use Modulith\Config\Modules;
use Modulith\Data\Module;

/** Each module is a service: the ones MODULITH_RUNS lists run in this process, each in its own context and database. */
final readonly class ModuleColocation implements Colocation
{
    public function __construct(
        private ModuleRegistry $registry,
        private ModuleContext $context,
        private Modules $config,
    ) {}

    public function local(): array
    {
        return array_map(static fn (Module $module): string => $module->name, $this->registry->local());
    }

    /** A module's own class, or a class of its foundation (its contracts, its RpcService). */
    public function serviceOf(string $class): ?string
    {
        return ($this->registry->forClass($class)
            ?? $this->registry->forFoundationClass($class, $this->config->getFoundationNamespace()))?->name;
    }

    public function current(): ?string
    {
        return $this->context->current()?->name;
    }

    public function within(?string $service, Closure $callback): mixed
    {
        return $this->context->within($service === null ? null : $this->registry->get($service), $callback);
    }

    public function connection(string $service): ?string
    {
        $module = $this->registry->find($service);

        return $module !== null && $module->hasDatabase ? $module->connection() : null;
    }

    public function classPath(string $service): string
    {
        return $this->registry->get($service)->classPath();
    }
}
