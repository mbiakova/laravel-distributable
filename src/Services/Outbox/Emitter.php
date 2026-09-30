<?php

declare(strict_types=1);

namespace Modulith\Services\Outbox;

use Illuminate\Database\DatabaseManager;
use Modulith\Contracts\Bus;
use Modulith\Events\Event;
use Modulith\Services\EnvelopeFactory;
use Modulith\Services\ModuleRegistry;

/**
 * Emission through the outbox: the publication row is written on the emitting module's own
 * connection, so it lands inside the caller's business transaction — one transactional write,
 * no dual write. Putting it on the wire is the publisher role's job alone (see Relay).
 */
final class Emitter implements Bus
{
    public function __construct(
        private readonly EnvelopeFactory $envelopes,
        private readonly ModuleRegistry $registry,
        private readonly DatabaseManager $db,
    ) {}

    public function emit(Event $event): void
    {
        $envelope = $this->envelopes->for($event);
        $module = $this->registry->get($envelope->emitter);

        $this->db->connection($module->connection())->table('event_publications')->insert([
            'id' => $envelope->id,
            'emitter' => $envelope->emitter,
            'name' => $envelope->name,
            'payload' => json_encode($envelope->payload, JSON_THROW_ON_ERROR),
            'headers' => json_encode($envelope->headers, JSON_THROW_ON_ERROR),
            'emitted_at' => $envelope->emittedAt,
        ]);
    }
}
