<?php

declare(strict_types=1);

namespace Modulith\Console\Commands;

use Illuminate\Console\Command;
use Modulith\Services\ModuleRegistry;
use Modulith\Services\Outbox\Relay;

/**
 * The publisher role: the only process that puts outbox rows on the wire. One per module set —
 * two would interleave a module's rows and break the order consumers replay them in.
 */
final class PublishEvents extends Command
{
    protected $signature = 'modulith:events:publish
        {--module=* : Limit the sweep to these modules}
        {--batch=100 : Rows claimed per pass}
        {--sleep=1 : Seconds between passes}
        {--once : Sweep once and exit}';

    protected $description = 'Relay pending outbox publications of every local module to the events transport.';

    public function handle(ModuleRegistry $registry, Relay $relay): int
    {
        $only = (array) $this->option('module');
        $batch = (int) $this->option('batch');

        $modules = array_filter(
            $registry->local(),
            fn ($module): bool => $module->hasDatabase && ($only === [] || in_array($module->name, $only, true)),
        );

        if ($modules === []) {
            $this->error('No local module with a database to sweep.');

            return self::FAILURE;
        }

        do {
            $relayed = 0;

            foreach ($modules as $module) {
                $relayed += $relay->drain($module, $batch);
            }

            if ($relayed > 0) {
                $this->line("→ published {$relayed}");
            }

            if (! $this->option('once')) {
                sleep(max(1, (int) $this->option('sleep')));
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }
}
