<?php

declare(strict_types=1);

namespace Modulith\Config;

use Illuminate\Contracts\Config\Repository;

/** The streamer.redis.* settings of the Redis Streams transport; read live, never snapshotted. */
final readonly class RedisStream
{
    public function __construct(private Repository $config) {}

    public function getConnection(): string
    {
        return (string) $this->config->get('streamer.redis.connection', 'default');
    }

    public function getPrefix(): string
    {
        return (string) $this->config->get('streamer.redis.prefix', 'modulith:events:');
    }

    public function getBlockMs(): int
    {
        return (int) $this->config->get('streamer.redis.block', 5_000);
    }

    public function getCount(): int
    {
        return (int) $this->config->get('streamer.redis.count', 50);
    }

    public function getClaimAfterMs(): int
    {
        return (int) $this->config->get('streamer.redis.claim_after', 60_000);
    }
}
