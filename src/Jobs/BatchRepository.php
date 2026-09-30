<?php

declare(strict_types=1);

namespace Modulith\Jobs;

use DateTimeInterface;
use Illuminate\Bus\Batch;
use Illuminate\Bus\BatchFactory;
use Illuminate\Bus\DatabaseBatchRepository;
use Illuminate\Bus\PendingBatch;
use Illuminate\Database\DatabaseManager;
use Modulith\Services\Modules\ModuleRegistry;

/**
 * Stores a batch in the database of the module owning its jobs — a batch belongs to one module.
 * While a job runs, the provider points the repository at that job's module (Queue::before);
 * reads go through the databases of every module this process runs.
 */
final class BatchRepository extends DatabaseBatchRepository
{
    public function __construct(
        BatchFactory $factory,
        private readonly DatabaseManager $db,
        string $default,
        string $table,
        private readonly ModuleRegistry $registry,
    ) {
        parent::__construct($factory, $db->connection(Databases::of($registry)[0] ?? $default), $table);
    }

    public function useModuleOf(string $class): void
    {
        $module = $this->registry->forClass($class);

        if ($module !== null && $module->hasDatabase) {
            $this->setConnection($this->db->connection($module->connection()));
        }
    }

    public function store(PendingBatch $batch)
    {
        $first = $batch->jobs->first();

        $class = match (true) {
            is_object($first) => $first::class,
            is_array($first) && isset($first[0]) && is_object($first[0]) => $first[0]::class,
            is_string($first) => $first,
            default => '',
        };

        $this->useModuleOf($class);

        return parent::store($batch);
    }

    public function find(string $batchId)
    {
        foreach (Databases::of($this->registry) as $connection) {
            $this->setConnection($this->db->connection($connection));

            if ($this->connection->table($this->table)->where('id', $batchId)->exists()) {
                break;
            }
        }

        return parent::find($batchId);
    }

    /** @return list<Batch> */
    public function get($limit = 50, $before = null)
    {
        $batches = [];

        foreach (Databases::of($this->registry) as $connection) {
            $this->setConnection($this->db->connection($connection));
            $batches = [...$batches, ...parent::get($limit, $before)];
        }

        usort($batches, fn (Batch $a, Batch $b): int => $b->createdAt <=> $a->createdAt);

        return array_slice($batches, 0, $limit);
    }

    public function cancel(string $batchId)
    {
        if ($this->find($batchId) !== null) {
            parent::cancel($batchId);
        }
    }

    public function delete(string $batchId)
    {
        if ($this->find($batchId) !== null) {
            parent::delete($batchId);
        }
    }

    public function prune(DateTimeInterface $before)
    {
        return $this->summed(fn (): int => parent::prune($before));
    }

    public function pruneUnfinished(DateTimeInterface $before)
    {
        return $this->summed(fn (): int => parent::pruneUnfinished($before));
    }

    public function pruneCancelled(DateTimeInterface $before)
    {
        return $this->summed(fn (): int => parent::pruneCancelled($before));
    }

    /** @param callable(): int $callback */
    private function summed(callable $callback): int
    {
        $total = 0;

        foreach (Databases::of($this->registry) as $connection) {
            $this->setConnection($this->db->connection($connection));
            $total += $callback();
        }

        return $total;
    }
}
