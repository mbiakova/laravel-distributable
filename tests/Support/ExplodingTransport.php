<?php

declare(strict_types=1);

namespace Distributable\Tests\Support;

use Microservices\Contracts\Stream\Transport;
use Microservices\Data\Envelope;
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
