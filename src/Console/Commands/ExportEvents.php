<?php

declare(strict_types=1);

namespace Modulith\Console\Commands;

use Illuminate\Console\Command;
use Modulith\Services\Modules\ModuleRegistry;
use Modulith\Services\Stream\Outbox\Archive;
use SplFileObject;

/** Keeps the outbox small without losing the journal: published rows move to a file. */
final class ExportEvents extends Command
{
    protected $signature = 'modulith:events:export
        {path : The JSON-lines file to append to}
        {--module=* : Limit to these modules}
        {--stream= : Only rows of this stream}
        {--until= : Only rows emitted before this date}
        {--where=* : Only rows matching column=value, or payload.field=value}
        {--acknowledged : Only rows every consumer has acknowledged}
        {--batch=1000 : Rows read and deleted per batch}';

    protected $description = 'Move published outbox rows to a file, then delete them from the outbox.';

    public function handle(ModuleRegistry $registry, Archive $archive): int
    {
        $file = new SplFileObject((string) $this->argument('path'), 'a');
        $only = (array) $this->option('module');
        $until = $this->option('until');
        $stream = $this->option('stream');
        $where = [];

        foreach ((array) $this->option('where') as $filter) {
            [$column, $value] = array_pad(explode('=', (string) $filter, 2), 2, '');
            $where[$column] = $value;
        }

        foreach ($registry->local() as $module) {
            if (! $module->hasDatabase || ($only !== [] && ! in_array($module->name, $only, true))) {
                continue;
            }

            $count = $archive->export(
                module: $module,
                file: $file,
                until: is_string($until) ? $until : null,
                stream: is_string($stream) ? $stream : null,
                where: $where,
                acknowledged: (bool) $this->option('acknowledged'),
                batch: max(1, (int) $this->option('batch')),
            );

            $this->line("→ {$module->name}: {$count} exported");
        }

        return self::SUCCESS;
    }
}
