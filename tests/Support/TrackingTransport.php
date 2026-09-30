<?php

declare(strict_types=1);

namespace Modulith\Tests\Support;

use Modulith\Contracts\Stream\TracksAcknowledgements;
use Modulith\Contracts\Stream\Transport;
use Modulith\Data\Envelope;

/** Numbers its entries 1-0, 2-0, …; entries up to $acknowledgedUpTo count as read by everyone. */
final class TrackingTransport implements TracksAcknowledgements, Transport
{
    public int $acknowledgedUpTo = 0;

    private int $next = 0;

    public function publish(Envelope $envelope): void
    {
        $this->publishTracked($envelope);
    }

    public function publishTracked(Envelope $envelope): string
    {
        return ++$this->next.'-0';
    }

    public function isAcknowledged(string $channel, string $entryId): bool
    {
        return (int) $entryId <= $this->acknowledgedUpTo;
    }

    public function consume(string $consumer, array $channels, callable $handle): void {}

    public function stop(): void {}
}
