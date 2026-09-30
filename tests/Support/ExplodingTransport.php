<?php

declare(strict_types=1);

namespace Modulith\Tests\Support;

use Modulith\Contracts\Transport;
use Modulith\Data\Envelope;
use RuntimeException;

/** A broker that is down. */
final class ExplodingTransport implements Transport
{
    public function publish(Envelope $envelope): void
    {
        throw new RuntimeException('transport down');
    }

    public function consume(string $consumer, array $channels, callable $handle): void {}

    public function stop(): void {}
}
