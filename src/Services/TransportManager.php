<?php

declare(strict_types=1);

namespace Modulith\Services;

use Illuminate\Contracts\Queue\Factory as Queue;
use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Support\Manager;
use Modulith\Config\QueueStream;
use Modulith\Config\RedisStream;
use Modulith\Config\Streamer;
use Modulith\Contracts\Transport;
use Modulith\Transports\ArrayTransport;
use Modulith\Transports\NullTransport;
use Modulith\Transports\QueueTransport;
use Modulith\Transports\RedisStreamTransport;

/**
 * Resolves the configured transport, and stays open: extend('kafka', fn ($app, $config) => …)
 * registers any implementation — the Laravel idiom (Cache::extend, Queue::addConnector). The
 * package closes nothing; it only ships a default.
 *
 * @method Transport driver(?string $driver = null)
 */
final class TransportManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return $this->container->make(Streamer::class)->getTransport();
    }

    public function createArrayDriver(): Transport
    {
        return new ArrayTransport;
    }

    public function createNullDriver(): Transport
    {
        return new NullTransport;
    }

    public function createRedisDriver(): Transport
    {
        return new RedisStreamTransport(
            $this->container->make(Redis::class),
            $this->container->make(RedisStream::class),
        );
    }

    public function createQueueDriver(): Transport
    {
        return new QueueTransport(
            $this->container->make(Queue::class),
            $this->container->make(QueueStream::class),
            $this->container->make(ModuleRegistry::class),
        );
    }
}
