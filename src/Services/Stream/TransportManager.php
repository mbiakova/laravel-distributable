<?php

declare(strict_types=1);

namespace Modulith\Services\Stream;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\Factory as Queue;
use Illuminate\Contracts\Redis\Factory as Redis;
use Modulith\Config\Streamer;
use Modulith\Contracts\Stream\Transport;
use Modulith\Exceptions\ConfigurationException;
use Modulith\Services\Modules\ModuleRegistry;
use Modulith\Transports\Stream\ArrayTransport;
use Modulith\Transports\Stream\NullTransport;
use Modulith\Transports\Stream\QueueTransport;
use Modulith\Transports\Stream\RedisStreamTransport;

/**
 * Resolves a named stream of streamer.streams to its transport, like QueueManager::connection(),
 * and stays open: extend('kafka', fn ($app, array $options, string $stream) => …) registers any
 * driver. The package closes nothing; it only ships defaults.
 */
final class TransportManager
{
    /** @var array<string, Closure(Container, array<string, mixed>, string): Transport> */
    private array $creators = [];

    /** @var array<string, array{options: array<string, mixed>, transport: Transport}> */
    private array $streams = [];

    public function __construct(private readonly Container $container) {}

    /** A stream is rebuilt when its options change, so config stays live. */
    public function stream(?string $name = null): Transport
    {
        $name ??= $this->container->make(Streamer::class)->getDefaultStream();
        $options = $this->container->make(Streamer::class)->getStream($name);

        if (($this->streams[$name]['options'] ?? null) !== $options) {
            $this->streams[$name] = ['options' => $options, 'transport' => $this->create($name, $options)];
        }

        return $this->streams[$name]['transport'];
    }

    /** @param Closure(Container, array<string, mixed>, string): Transport $creator */
    public function extend(string $driver, Closure $creator): static
    {
        $this->creators[$driver] = $creator;
        $this->streams = [];

        return $this;
    }

    /** @param array<string, mixed> $options */
    private function create(string $name, array $options): Transport
    {
        $driver = (string) ($options['driver'] ?? '');

        if (isset($this->creators[$driver])) {
            return ($this->creators[$driver])($this->container, $options, $name);
        }

        return match ($driver) {
            'redis' => new RedisStreamTransport($this->container->make(Redis::class), $this->container->make(Streamer::class)->getRedisStream($name)),
            'queue' => new QueueTransport($this->container->make(Queue::class), $this->container->make(Streamer::class)->getQueueStream($name), $this->container->make(ModuleRegistry::class)),
            'array' => new ArrayTransport,
            'null' => new NullTransport,
            default => throw ConfigurationException::unknownDriver($driver, $name),
        };
    }
}
