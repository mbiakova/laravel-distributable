<?php

declare(strict_types=1);

namespace Distributable\Testing;

use Closure;
use Distributable\Data\Module;
use Distributable\Exceptions\ModuleException;
use Distributable\Services\Modules\ModuleContext;
use Distributable\Services\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Microservices\Data\Envelope;
use Microservices\Services\Stream\Dispatcher;
use PHPUnit\Framework\Assert;
use ReflectionClass;
use ReflectionFunction;

/** For a test case: a test in a module's folder runs in that module, and any test can borrow a module for a piece of code. */
trait ModuleAware
{
    // Without it, $this->artisan() fires no CommandStarting, and a module command runs outside its module.
    use WithConsoleEvents;

    /** Laravel calls it once the application boots: the module is read from the test file's path, so PHPUnit and Pest agree. */
    protected function setUpModuleAware(): void
    {
        // Pest evaluates each test file into a class: the file it came from is kept in $__filename.
        $class = new ReflectionClass($this);
        $file = (string) ($class->hasProperty('__filename') ? $class->getStaticPropertyValue('__filename') : $class->getFileName());
        $module = Arr::first(
            $this->app->make(ModuleRegistry::class)->all(),
            static fn (Module $module): bool => str_starts_with($file, $module->path().DIRECTORY_SEPARATOR),
        );

        $this->app->make(ModuleContext::class)->switchTo($module);
    }

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

    /**
     * Hands a module an event as the emitting module would have announced it, so the module is tested without its emitter running.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function receive(string $module, string $name, array $payload = [], int $version = 1): void
    {
        $envelope = new Envelope(
            id: (string) Str::uuid7(),
            emitter: Str::before($name, '.'),
            name: $name,
            payload: $payload,
            headers: [],
            emittedAt: Date::now()->toImmutable(),
            version: $version,
        );

        $this->app->make(Dispatcher::class)->dispatch($envelope, $this->app->make(ModuleRegistry::class)->get($module)->name);
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
