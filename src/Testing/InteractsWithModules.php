<?php

declare(strict_types=1);

namespace Modulith\Testing;

use Closure;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Modulith\Exceptions\ModuleException;
use Modulith\Services\Modules\ModuleContext;
use Modulith\Services\Modules\ModuleRegistry;
use PHPUnit\Framework\Assert;
use ReflectionFunction;

/** For a test case: run a piece of the test as the module's own code would run, on its database. */
trait InteractsWithModules
{
    // Without it, $this->artisan() fires no CommandStarting, and a module command runs outside its module.
    use WithConsoleEvents;

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

    /**
     * The module is read from the class, so the test names no module.
     *
     * @template T
     *
     * @param  class-string  $class
     * @param  Closure(): T  $callback
     * @return T
     */
    protected function inModuleOf(string $class, Closure $callback): mixed
    {
        $module = $this->app->make(ModuleRegistry::class)->forClass($class) ?? throw ModuleException::outsideModule($class);

        return $this->app->make(ModuleContext::class)->within($module, $callback);
    }

    /** Event::assertListening() for a listener a module declares in $listen, which Laravel only sees as a closure. */
    protected function assertListeningInModule(string $event, string $listener): void
    {
        $built = static fn (mixed $candidate): mixed => $candidate instanceof Closure ? (new ReflectionFunction($candidate))->getStaticVariables()['handle'] ?? null : null;

        $attached = array_filter(
            $this->app->make('events')->getRawListeners()[$event] ?? [],
            static fn (mixed $candidate): bool => ($handle = $built($candidate)) instanceof Closure
                && ((new ReflectionFunction($handle))->getStaticVariables()['listener'] ?? null) === $listener,
        );

        Assert::assertNotEmpty($attached, "Event [{$event}] does not have the [{$listener}] module listener attached to it.");
    }
}
