<?php

declare(strict_types=1);

namespace Modulith\Services\Stream\Outbox;

use Illuminate\Database\DatabaseManager;
use Modulith\Data\Envelope;
use Modulith\Services\Modules\ModuleRegistry;

/**
 * Writes a publication row on the emitting module's own connection, so it lands inside the
 * caller's business transaction — one transactional write, no dual write. Putting it on the wire
 * is the publisher role's job alone (see Relay).
 */
final class Writer
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly DatabaseManager $db,
    ) {}

    public function write(Envelope $envelope): void
    {
        $module = $this->registry->get($envelope->emitter);

        $this->db->connection($module->connection())->table('event_publications')->insert([
            'id' => $envelope->id,
            'emitter' => $envelope->emitter,
            'name' => $envelope->name,
            'payload' => json_encode($envelope->payload, JSON_THROW_ON_ERROR),
            'headers' => json_encode($envelope->headers, JSON_THROW_ON_ERROR),
            'emitted_at' => $envelope->emittedAt,
            'recipients' => json_encode($envelope->recipients, JSON_THROW_ON_ERROR),
            'stream' => $envelope->stream,
            'version' => $envelope->version,
        ]);
    }
}
