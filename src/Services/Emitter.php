<?php

declare(strict_types=1);

namespace Modulith\Services;

use Modulith\Contracts\Bus;
use Modulith\Events\Event;

/**
 * The single emission path: an event becomes an envelope stamped with its emitting module, and
 * the configured transport decides what emitting means. Module code never learns which one is
 * wired — that is what makes mono and micro the same code.
 */
final class Emitter implements Bus
{
    public function __construct(
        private readonly EnvelopeFactory $envelopes,
        private readonly TransportManager $transports,
    ) {}

    public function emit(Event $event): void
    {
        $this->transports->driver()->publish($this->envelopes->for($event));
    }
}
