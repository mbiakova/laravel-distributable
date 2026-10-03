<?php

declare(strict_types=1);

namespace Distributable\Support;

use Closure;
use Distributable\Services\Modules\ModuleContext;
use Illuminate\Container\Container;
use Laravel\Octane\Contracts\DispatchesTasks;
use Laravel\Octane\SequentialTaskDispatcher;
use Laravel\Octane\Swoole\ServerStateFile;
use Laravel\Octane\Swoole\SwooleHttpTaskDispatcher;
use Laravel\Octane\Swoole\SwooleTaskDispatcher;

/**
 * Octane::concurrently() runs each task in a task worker, which has none of the request's module:
 * each task is given its module. Picks the dispatcher Octane would have picked.
 */
final readonly class ModuleTaskDispatcher implements DispatchesTasks
{
    private const string SWOOLE_SERVER = 'Swoole\Http\Server';

    public function __construct(private ModuleContext $context) {}

    /**
     * @param  array<array-key, mixed>  $tasks
     * @return array<array-key, mixed>
     */
    public function resolve(array $tasks, int $waitMilliseconds = 3000): array
    {
        return $this->dispatcher()->resolve($this->bind($tasks), $waitMilliseconds);
    }

    /** @param array<array-key, mixed> $tasks */
    public function dispatch(array $tasks): void
    {
        $this->dispatcher()->dispatch($this->bind($tasks));
    }

    /**
     * @param  array<array-key, mixed>  $tasks
     * @return array<array-key, mixed>
     */
    private function bind(array $tasks): array
    {
        return array_map(fn (mixed $task): mixed => $task instanceof Closure ? $this->context->bind($task) : $task, $tasks);
    }

    private function dispatcher(): DispatchesTasks
    {
        $app = Container::getInstance();

        if ($app->bound(self::SWOOLE_SERVER)) {
            return new SwooleTaskDispatcher;
        }

        if (class_exists(self::SWOOLE_SERVER)) {
            /** @var array{state?: array{host?: string, port?: string}} $state */
            $state = $app->make(ServerStateFile::class)->read();

            return new SwooleHttpTaskDispatcher($state['state']['host'] ?? '127.0.0.1', $state['state']['port'] ?? '8000', new SequentialTaskDispatcher);
        }

        return new SequentialTaskDispatcher;
    }
}
