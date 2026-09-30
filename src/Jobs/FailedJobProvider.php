<?php

declare(strict_types=1);

namespace Modulith\Jobs;

use DateTimeInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider;
use Modulith\Services\Modules\ModuleRegistry;

/**
 * Stores a failed job in the database of the module owning the job class; reads go through the
 * databases of every module this process runs.
 */
final class FailedJobProvider extends DatabaseUuidFailedJobProvider
{
    public function __construct(
        ConnectionResolverInterface $resolver,
        string $database,
        string $table,
        private readonly ModuleRegistry $registry,
    ) {
        parent::__construct($resolver, $database, $table);
    }

    public function log($connection, $queue, $payload, $exception)
    {
        return $this->on($this->connectionFor($payload), fn () => parent::log($connection, $queue, $payload, $exception));
    }

    /** @return array<int, mixed> */
    public function ids($queue = null)
    {
        return $this->merged(fn () => parent::ids($queue));
    }

    /** @return array<int, mixed> */
    public function all()
    {
        return $this->merged(fn () => parent::all());
    }

    public function find($id)
    {
        foreach ($this->connections() as $connection) {
            $record = $this->on($connection, fn () => parent::find($id));

            if ($record !== null) {
                return $record;
            }
        }

        return null;
    }

    public function forget($id)
    {
        foreach ($this->connections() as $connection) {
            if ($this->on($connection, fn () => parent::forget($id))) {
                return true;
            }
        }

        return false;
    }

    public function flush($hours = null)
    {
        foreach ($this->connections() as $connection) {
            $this->on($connection, fn () => parent::flush($hours));
        }
    }

    public function prune(DateTimeInterface $before)
    {
        return array_sum(array_map(fn (string $connection): int => $this->on($connection, fn () => parent::prune($before)), $this->connections()));
    }

    public function count($connection = null, $queue = null)
    {
        return array_sum(array_map(fn (string $module): int => $this->on($module, fn () => parent::count($connection, $queue)), $this->connections()));
    }

    /** A job outside every module goes to the first module database, else to the configured one. */
    private function connectionFor(string $payload): string
    {
        $decoded = json_decode($payload, true);
        $class = is_array($decoded) ? (string) ($decoded['data']['commandName'] ?? $decoded['displayName'] ?? '') : '';
        $module = $this->registry->forClass($class);

        return $module !== null && $module->hasDatabase
            ? $module->connection()
            : ($this->connections()[0] ?? $this->database);
    }

    /** @return list<string> */
    private function connections(): array
    {
        return Databases::of($this->registry) ?: [$this->database];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function on(string $connection, callable $callback): mixed
    {
        $previous = $this->database;
        $this->database = $connection;

        try {
            return $callback();
        } finally {
            $this->database = $previous;
        }
    }

    /**
     * @param  callable(): iterable<int, mixed>  $callback
     * @return array<int, mixed>
     */
    private function merged(callable $callback): array
    {
        $merged = [];

        foreach ($this->connections() as $connection) {
            $merged = [...$merged, ...$this->on($connection, $callback)];
        }

        return $merged;
    }
}
