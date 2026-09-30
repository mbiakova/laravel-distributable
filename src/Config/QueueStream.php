<?php

declare(strict_types=1);

namespace Modulith\Config;

use Illuminate\Contracts\Config\Repository;

/** The streamer.queue.* settings of the queue transport; read live, never snapshotted. */
final readonly class QueueStream
{
    public function __construct(private Repository $config) {}

    /** Null is the application's default queue connection. */
    public function getConnection(): ?string
    {
        $connection = $this->config->get('streamer.queue.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    public function getPrefix(): string
    {
        return (string) $this->config->get('streamer.queue.prefix', 'modulith-events-');
    }

    public function getSleep(): int
    {
        return (int) $this->config->get('streamer.queue.sleep', 1);
    }
}
