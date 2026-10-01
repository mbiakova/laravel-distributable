<?php

declare(strict_types=1);

namespace Modulith\Console\Commands;

use Illuminate\Console\Command;
use Modulith\Contracts\Stream\TrimsStreams;
use Modulith\Services\Stream\TransportManager;

final class TrimEvents extends Command
{
    protected $signature = 'modulith:events:trim
        {--stream= : The stream to trim (default: modulith.events.stream)}';

    protected $description = 'Drop the stream entries every consumer has acknowledged.';

    public function handle(TransportManager $transports): int
    {
        $transport = $transports->stream($this->option('stream') !== null ? (string) $this->option('stream') : null);

        if (! $transport instanceof TrimsStreams) {
            $this->info('This transport keeps nothing after delivery: nothing to trim.');

            return self::SUCCESS;
        }

        $this->line($transport->trim().' dropped');

        return self::SUCCESS;
    }
}
