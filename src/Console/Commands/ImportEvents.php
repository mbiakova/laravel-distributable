<?php

declare(strict_types=1);

namespace Modulith\Console\Commands;

use Illuminate\Console\Command;
use Modulith\Services\Stream\Outbox\Archive;
use SplFileObject;

/** Replays an export: its rows become pending publications again, put on the wire by the publisher. */
final class ImportEvents extends Command
{
    protected $signature = 'modulith:events:import
        {path : A file written by modulith:events:export}
        {--batch=1000 : Rows inserted per batch}';

    protected $description = 'Queue the rows of an export for publication again.';

    public function handle(Archive $archive): int
    {
        $count = $archive->import(new SplFileObject((string) $this->argument('path'), 'r'), max(1, (int) $this->option('batch')));

        $this->line("→ {$count} imported");

        return self::SUCCESS;
    }
}
