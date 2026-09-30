<?php

declare(strict_types=1);

namespace Modulith\Testing;

use Closure;
use Modulith\Services\Modules\ModuleContext;
use Modulith\Services\Modules\ModuleRegistry;

/** For a test case: run a piece of the test as the module's own code would run, on its database. */
trait InteractsWithModules
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    protected function inModule(string $module, Closure $callback): mixed
    {
        return $this->app->make(ModuleContext::class)->within($this->app->make(ModuleRegistry::class)->get($module), $callback);
    }
}
