<?php

declare(strict_types=1);

namespace Modulith\Config;

use Illuminate\Contracts\Config\Repository;

/** The streamer.streams.{name}.* settings of a stream on the `queue` driver; read live, never snapshotted. */
final readonly class QueueStream
{
    public function __construct(private Repository $config, public string $name) {}

    /** Null is the application's default queue connection. */
    public function getConnection(): ?string
    {
        $connection = $this->config->get("streamer.streams.{$this->name}.connection");

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    public function getPrefix(): string
    {
        return (string) $this->config->get("streamer.streams.{$this->name}.prefix", "modulith-{$this->name}-");
    }

    public function getSleep(): int
    {
        return (int) $this->config->get("streamer.streams.{$this->name}.sleep", 1);
    }
}
