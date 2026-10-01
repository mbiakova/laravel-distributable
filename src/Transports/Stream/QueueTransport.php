<?php

declare(strict_types=1);

namespace Modulith\Transports\Stream;

use Illuminate\Contracts\Queue\Factory as Queue;
use Illuminate\Contracts\Queue\Queue as Connection;
use Modulith\Config\QueueStream;
use Modulith\Contracts\Stream\RedeliversEnvelopes;
use Modulith\Contracts\Stream\Transport;
use Modulith\Data\Envelope;
use Modulith\Services\Modules\ModuleRegistry;
use Throwable;

/**
 * Events over a Laravel queue connection — database, sqs, beanstalkd — for a stack without Redis.
 *
 * A queue hands each job to one reader, so publishing fans out: one copy per declared module,
 * on its own queue `{key}-{module}`. A failed envelope is released and comes back
 * after the ones queued behind it: this transport does not keep the order across a failure.
 */
final class QueueTransport implements RedeliversEnvelopes, Transport
{
    private const int MAX_BACKOFF_SECONDS = 60;

    private bool $listening = true;

    public function __construct(
        private readonly Queue $queues,
        private readonly QueueStream $config,
        private readonly ModuleRegistry $registry,
    ) {}

    public function publish(Envelope $envelope): void
    {
        foreach ($this->registry->all() as $module) {
            if ($envelope->isFor($module->name)) {
                $this->connection()->pushRaw($envelope->toJson(), $this->queue($module->name));
            }
        }
    }

    public function consume(string $consumer, array $channels, callable $handle): void
    {
        $failures = 0;

        while ($this->listening) {
            $job = $this->connection()->pop($this->queue($consumer));

            if ($job === null) {
                sleep(max(1, $this->config->getSleep()));

                continue;
            }

            $envelope = Envelope::fromJson($job->getRawBody());

            if (! in_array($envelope->emitter, $channels, true)) {
                $job->delete();

                continue;
            }

            try {
                $handle($envelope);
            } catch (Throwable $e) {
                report($e);
                $job->release(min(self::MAX_BACKOFF_SECONDS, 2 ** min(++$failures, 6)));

                continue;
            }

            $job->delete();
            $failures = 0;
        }
    }

    public function stop(): void
    {
        $this->listening = false;
    }

    private function queue(string $module): string
    {
        return $this->config->getKey().'-'.$module;
    }

    private function connection(): Connection
    {
        return $this->queues->connection($this->config->getConnection());
    }
}
