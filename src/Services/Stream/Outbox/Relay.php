<?php

declare(strict_types=1);

namespace Modulith\Services\Stream\Outbox;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Modulith\Contracts\Stream\TracksAcknowledgements;
use Modulith\Data\Envelope;
use Modulith\Data\Module;
use Modulith\Services\Stream\TransportManager;
use stdClass;
use Throwable;

/**
 * Puts a module's pending publications on the wire in `sequence` order — the order a consumer
 * applies them in when it rebuilds its state by replaying the stream. That order holds only with
 * one relay per module, so the publisher role is the sole caller.
 *
 * Rows are claimed by FLAG (`published_at IS NULL`), never by a cursor: a cursor loses the rows
 * of a long transaction that commits after it has gone past.
 *
 * Delivery is at-least-once — a crash between publishing and marking republishes. That is why
 * the consumption guard exists.
 */
final class Relay
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly TransportManager $transports,
    ) {}

    /**
     * A row that fails stops the pass: publishing the next one would put it on the wire ahead of
     * the one that failed. Only the module's own rows: modules sharing a database share the table.
     *
     * @return int the number of rows published
     */
    public function drain(Module $module, int $batch = 100): int
    {
        $rows = $this->table($module)
            ->where('emitter', $module->name)
            ->whereNull('published_at')
            ->orderBy('sequence')
            ->limit($batch)
            ->get();

        $published = 0;

        foreach ($rows as $row) {
            if (! $this->publish($module, $row)) {
                break;
            }

            $published++;
        }

        return $published;
    }

    /** Puts already-published rows back in the queue, to rebuild an emptied stream from the journal. */
    public function requeue(Module $module, ?string $since = null): int
    {
        return $this->table($module)
            ->where('emitter', $module->name)
            ->whereNotNull('published_at')
            ->when($since !== null, fn (Builder $q) => $q->where('emitted_at', '>=', $since))
            ->update(['published_at' => null, 'attempts' => 0, 'last_error' => null]);
    }

    private function publish(Module $module, stdClass $row): bool
    {
        try {
            $envelope = $this->envelope($row);
            $transport = $this->transports->stream($envelope->stream);
            $entryId = null;

            if ($transport instanceof TracksAcknowledgements) {
                $entryId = $transport->publishTracked($envelope);
            } else {
                $transport->publish($envelope);
            }
        } catch (Throwable $e) {
            $this->table($module)->where('sequence', $row->sequence)->update([
                'attempts' => (int) $row->attempts + 1,
                'last_error' => Str::limit($e->getMessage(), 1000),
            ]);

            return false;
        }

        $this->table($module)->where('sequence', $row->sequence)->update([
            'published_at' => Date::now(),
            'stream_id' => $entryId,
            'last_error' => null,
        ]);

        return true;
    }

    private function envelope(stdClass $row): Envelope
    {
        return Envelope::fromArray([
            'id' => $row->id,
            'emitter' => $row->emitter,
            'name' => $row->name,
            'payload' => json_decode((string) $row->payload, true, 512, JSON_THROW_ON_ERROR),
            'headers' => json_decode((string) $row->headers, true, 512, JSON_THROW_ON_ERROR),
            'emitted_at' => $row->emitted_at,
            'recipients' => json_decode((string) $row->recipients, true, 512, JSON_THROW_ON_ERROR),
            'stream' => $row->stream,
        ]);
    }

    private function table(Module $module): Builder
    {
        return $this->connection($module)->table('event_publications');
    }

    private function connection(Module $module): Connection
    {
        return $this->db->connection($module->connection());
    }
}
