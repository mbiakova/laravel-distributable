<?php

declare(strict_types=1);

namespace Modulith\Config;

use Illuminate\Contracts\Config\Repository;

/** The modulith.events.streams.{name}.* settings of a stream on the `redis` driver; read live, never snapshotted. */
final readonly class RedisStream
{
    public function __construct(private Repository $config, public string $name) {}

    public function getConnection(): string
    {
        return (string) ($this->config->get("modulith.events.streams.{$this->name}.connection") ?? 'default');
    }

    /** The one Redis stream every module writes this stream's events to. */
    public function getKey(): string
    {
        return (string) $this->config->get("modulith.events.streams.{$this->name}.key", "modulith:{$this->name}");
    }

    public function getBlockMs(): int
    {
        return (int) $this->config->get("modulith.events.streams.{$this->name}.block", 5_000);
    }

    public function getCount(): int
    {
        return (int) $this->config->get("modulith.events.streams.{$this->name}.count", 50);
    }

    public function getClaimAfterMs(): int
    {
        return (int) $this->config->get("modulith.events.streams.{$this->name}.claim_after", 60_000);
    }

    /** `block` (default): a failed entry is retried before any later one. `skip`: later entries go on, the failed one comes back after claim_after. */
    public function blocksOnFailure(): bool
    {
        return $this->config->get("modulith.events.streams.{$this->name}.on_failure", 'block') !== 'skip';
    }
}
