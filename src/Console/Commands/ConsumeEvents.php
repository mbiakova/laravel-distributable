<?php

declare(strict_types=1);

namespace Modulith\Console\Commands;

use Illuminate\Console\Command;
use Modulith\Contracts\Transport;
use Modulith\Data\Envelope;
use Modulith\Services\Dispatcher;
use Modulith\Services\ModuleRegistry;

/**
 * The consumer role: reads the envelopes published by every module and runs this module's
 * handlers. The consuming module names the subscription (its own cursor), so adding a consumer
 * disturbs none of the others.
 */
final class ConsumeEvents extends Command
{
    protected $signature = 'modulith:events:consume {--module= : The consuming module (default: the only local one)}';

    protected $description = 'Consume events from the transport and run this module handlers.';

    public function handle(ModuleRegistry $registry, Transport $transport, Dispatcher $dispatcher): int
    {
        $consumer = $this->consumer($registry);

        if ($consumer === null) {
            $this->error('Several modules boot here — name the consuming one with --module.');

            return self::FAILURE;
        }

        $channels = array_map(fn ($module): string => $module->name, $registry->all());

        $this->info("Consuming as [{$consumer}] from: ".implode(', ', $channels));

        $this->stopGracefullyOnSignal($transport);

        $transport->consume($consumer, $channels, function (Envelope $envelope) use ($dispatcher): void {
            $dispatcher->dispatch($envelope);
        });

        return self::SUCCESS;
    }

    /**
     * A container restart must not kill a handler mid-flight: on SIGTERM the loop finishes the
     * envelope it holds, then returns.
     */
    private function stopGracefullyOnSignal(Transport $transport): void
    {
        if (! extension_loaded('pcntl')) {
            return;
        }

        pcntl_async_signals(true);

        foreach ([SIGTERM, SIGINT] as $signal) {
            pcntl_signal($signal, function () use ($transport): void {
                $this->line('Stopping after the current envelope…');
                $transport->stop();
            });
        }
    }

    private function consumer(ModuleRegistry $registry): ?string
    {
        $module = $this->option('module');

        if ($module !== null) {
            return $registry->get((string) $module)->name;
        }

        $local = $registry->local();

        return count($local) === 1 ? $local[0]->name : null;
    }
}
