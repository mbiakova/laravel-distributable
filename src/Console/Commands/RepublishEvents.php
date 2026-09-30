<?php

declare(strict_types=1);

namespace Modulith\Console\Commands;

use Illuminate\Console\Command;
use Modulith\Services\ModuleRegistry;
use Modulith\Services\Outbox\Relay;

/**
 * For the day the broker is emptied while the outboxes are intact: the stream is rebuilt from
 * each module's own journal, in order. The consumption guard turns what consumers already
 * applied into no-ops, so replaying costs time and changes nothing.
 */
final class RepublishEvents extends Command
{
    protected $signature = 'modulith:events:republish
        {--module=* : Limit to these modules}
        {--since= : Only rows emitted on or after this date}
        {--force : Skip the confirmation}';

    protected $description = 'Queue already-published outbox rows again, to rebuild an emptied stream.';

    public function handle(ModuleRegistry $registry, Relay $relay): int
    {
        $only = (array) $this->option('module');
        $since = $this->option('since');

        foreach ($registry->local() as $module) {
            if (! $module->hasDatabase || ($only !== [] && ! in_array($module->name, $only, true))) {
                continue;
            }

            if (! $this->option('force') && ! $this->confirm("Republish the outbox of [{$module->name}]?")) {
                continue;
            }

            $count = $relay->requeue($module, is_string($since) ? $since : null);

            $this->line("→ {$module->name}: {$count} requeued");
        }

        return self::SUCCESS;
    }
}
