<?php

declare(strict_types=1);

namespace Modulith\Services\Stream;

use Modulith\Config\Streamer;
use Modulith\Contracts\Stream\Bus;
use Modulith\Events\Event;
use Modulith\Services\Stream\Outbox\Writer;

/**
 * The single emission path: an event becomes an envelope stamped with its emitting module, then
 * goes to its stream's outbox or straight onto its stream. Module code never learns which one is
 * wired — that is what makes mono and micro the same code.
 */
final class Emitter implements Bus
{
    public function __construct(
        private readonly EnvelopeFactory $envelopes,
        private readonly TransportManager $transports,
        private readonly Writer $outbox,
        private readonly Streamer $config,
    ) {}

    public function emit(Event $event): void
    {
        $envelope = $this->envelopes->for($event);

        $this->config->usesOutbox($envelope->stream)
            ? $this->outbox->write($envelope)
            : $this->transports->stream($envelope->stream)->publish($envelope);
    }
}
