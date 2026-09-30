<?php

declare(strict_types=1);

namespace Modulith\Config;

use Illuminate\Contracts\Config\Repository;

/** The streamer.streams.{name}.* settings of a stream on the `redis` driver; read live, never snapshotted. */
final readonly class RedisStream
{
    public function __construct(private Repository $config, public string $name) {}

    public function getConnection(): string
    {
        return (string) $this->config->get("streamer.streams.{$this->name}.connection", 'default');
    }

    public function getPrefix(): string
    {
        return (string) $this->config->get("streamer.streams.{$this->name}.prefix", "modulith:{$this->name}:");
    }

    public function getBlockMs(): int
    {
        return (int) $this->config->get("streamer.streams.{$this->name}.block", 5_000);
    }

    public function getCount(): int
    {
        return (int) $this->config->get("streamer.streams.{$this->name}.count", 50);
    }

    public function getClaimAfterMs(): int
    {
        return (int) $this->config->get("streamer.streams.{$this->name}.claim_after", 60_000);
    }
}
