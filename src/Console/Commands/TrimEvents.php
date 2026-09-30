<?php

declare(strict_types=1);

namespace Modulith\Console\Commands;

use Illuminate\Console\Command;
use Modulith\Contracts\Transport;
use Modulith\Contracts\TrimsStreams;
use Modulith\Services\ModuleRegistry;

final class TrimEvents extends Command
{
    protected $signature = 'modulith:events:trim {--module=* : Limit the trim to these emitters}';

    protected $description = 'Drop the stream entries every consumer has acknowledged.';

    public function handle(ModuleRegistry $registry, Transport $transport): int
    {
        if (! $transport instanceof TrimsStreams) {
            $this->info('This transport keeps nothing after delivery: nothing to trim.');

            return self::SUCCESS;
        }

        $only = (array) $this->option('module');

        foreach ($registry->all() as $module) {
            if ($only === [] || in_array($module->name, $only, true)) {
                $this->line("{$module->name}: ".$transport->trim($module->name).' dropped');
            }
        }

        return self::SUCCESS;
    }
}
