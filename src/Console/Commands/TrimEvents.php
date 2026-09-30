<?php

declare(strict_types=1);

namespace Modulith\Console\Commands;

use Illuminate\Console\Command;
use Modulith\Contracts\Stream\TrimsStreams;
use Modulith\Services\Modules\ModuleRegistry;
use Modulith\Services\Stream\TransportManager;

final class TrimEvents extends Command
{
    protected $signature = 'modulith:events:trim
        {--module=* : Limit the trim to these emitters}
        {--stream= : The stream to trim (default: streamer.default)}';

    protected $description = 'Drop the stream entries every consumer has acknowledged.';

    public function handle(ModuleRegistry $registry, TransportManager $transports): int
    {
        $transport = $transports->stream($this->option('stream') !== null ? (string) $this->option('stream') : null);

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
