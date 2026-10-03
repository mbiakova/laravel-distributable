<?php

declare(strict_types=1);

namespace Distributable\Support;

use Carbon\CarbonInterval;
use Closure;
use Distributable\Services\Modules\ModuleContext;
use Illuminate\Contracts\Concurrency\Driver;
use Illuminate\Support\Defer\DeferredCallback;

/** Concurrency::run() starts child processes that receive only the closure: each task is given its module. */
final readonly class ModuleConcurrencyDriver implements Driver
{
    public function __construct(
        private Driver $driver,
        private ModuleContext $context,
    ) {}

    /**
     * @param  Closure|array<array-key, Closure>  $tasks
     * @return array<array-key, mixed>
     */
    public function run(Closure|array $tasks, CarbonInterval|int|null $timeout = null): array
    {
        // Laravel 12's driver takes no timeout: one is passed on only when the caller gave it.
        return $this->driver->run($this->bind($tasks), ...($timeout === null ? [] : [$timeout]));
    }

    /** @param Closure|array<array-key, Closure> $tasks */
    public function defer(Closure|array $tasks): DeferredCallback
    {
        return $this->driver->defer($this->bind($tasks));
    }

    /**
     * @param  Closure|array<array-key, Closure>  $tasks
     * @return Closure|array<array-key, Closure>
     */
    private function bind(Closure|array $tasks): Closure|array
    {
        return $tasks instanceof Closure
            ? $this->context->bind($tasks)
            : array_map($this->context->bind(...), $tasks);
    }
}
