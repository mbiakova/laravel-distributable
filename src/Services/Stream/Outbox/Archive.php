<?php

declare(strict_types=1);

namespace Modulith\Services\Stream\Outbox;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modulith\Contracts\Stream\TracksAcknowledgements;
use Modulith\Data\Module;
use Modulith\Exceptions\ConfigurationException;
use Modulith\Services\Modules\ModuleRegistry;
use Modulith\Services\Stream\TransportManager;
use SplFileObject;
use stdClass;

/** Moves published outbox rows to a JSON-lines file and back, in batches. */
final class Archive
{
    private const array COLUMNS = ['id', 'emitter', 'name', 'payload', 'headers', 'emitted_at', 'recipients', 'stream', 'version'];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly ModuleRegistry $registry,
        private readonly TransportManager $transports,
    ) {}

    /**
     * Writes the module's matching published rows to $file, then deletes them. With $acknowledged,
     * it stops at the first row some consumer has not acknowledged yet.
     *
     * @param  array<string, string>  $where  column, or payload.{field}, => value
     */
    public function export(
        Module $module,
        SplFileObject $file,
        ?string $until = null,
        ?string $stream = null,
        array $where = [],
        bool $acknowledged = false,
        int $batch = 1000,
    ): int {
        $exported = 0;

        do {
            $rows = $this->published($module, $until, $stream, $where)->orderBy('sequence')->limit($batch)->get();
            $fetched = $rows->count();

            if ($acknowledged) {
                $rows = $rows->takeWhile(fn (stdClass $row): bool => $this->isAcknowledged($row));
            }

            foreach ($rows as $row) {
                $file->fwrite(json_encode($this->line($row), JSON_THROW_ON_ERROR)."\n");
            }

            $this->table($module)->whereIn('sequence', $rows->pluck('sequence')->all())->delete();
            $exported += $rows->count();
        } while ($fetched === $batch && $rows->count() === $fetched);

        return $exported;
    }

    private function isAcknowledged(stdClass $row): bool
    {
        $transport = $this->transports->stream((string) $row->stream);

        if (! $transport instanceof TracksAcknowledgements) {
            throw ConfigurationException::untrackedAcknowledgements((string) $row->stream);
        }

        return $row->stream_id !== null && $transport->isAcknowledged((string) $row->stream_id);
    }

    /** Puts the file's rows back as pending publications of their emitter, in file order; a row already there is skipped. */
    public function import(SplFileObject $file, int $batch = 1000): int
    {
        $imported = 0;
        $pending = [];

        foreach ($file as $line) {
            if (! is_string($line) || trim($line) === '') {
                continue;
            }

            /** @var array<string, mixed> $row */
            $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

            // A file exported before payloads had a version: every row of a batch needs the same columns.
            $pending[(string) $row['emitter']][] = $row + ['version' => 1];

            if (count($pending, COUNT_RECURSIVE) - count($pending) >= $batch) {
                $imported += $this->insert($pending);
                $pending = [];
            }
        }

        return $imported + $this->insert($pending);
    }

    /** @param array<string, list<array<string, mixed>>> $pending */
    private function insert(array $pending): int
    {
        $inserted = 0;

        foreach ($pending as $emitter => $rows) {
            $inserted += $this->table($this->registry->get($emitter))->insertOrIgnore($rows);
        }

        return $inserted;
    }

    /** @param array<string, string> $where */
    private function published(Module $module, ?string $until, ?string $stream, array $where): Builder
    {
        $query = $this->table($module)
            ->where('emitter', $module->name)
            ->whereNotNull('published_at')
            ->when($until !== null, fn (Builder $q) => $q->where('emitted_at', '<', $until))
            ->when($stream !== null, fn (Builder $q) => $q->where('stream', $stream));

        foreach ($where as $column => $value) {
            if (preg_match('/^(name|emitter|stream|payload(\.\w+)+)$/', $column) !== 1) {
                throw ConfigurationException::invalidExportFilter($column);
            }

            $query->where(str_replace('.', '->', $column), $value);
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private function line(stdClass $row): array
    {
        return array_intersect_key((array) $row, array_flip(self::COLUMNS));
    }

    private function table(Module $module): Builder
    {
        return $this->db->connection($module->connection())->table('event_publications');
    }
}
