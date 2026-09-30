<?php

declare(strict_types=1);

namespace Modulith\Tests\Support;

use Modulith\Contracts\Stream\Transport;
use Modulith\Data\Envelope;

/** A consumer-supplied transport, registered through TransportManager::extend(). */
final class RecordingTransport implements Transport
{
    /** @var list<Envelope> */
    public array $published = [];

    public function publish(Envelope $envelope): void
    {
        $this->published[] = $envelope;
    }

    public function consume(string $consumer, array $channels, callable $handle): void {}

    public function stop(): void {}
}
