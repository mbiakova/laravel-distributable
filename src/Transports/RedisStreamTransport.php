<?php

declare(strict_types=1);

namespace Modulith\Transports;

use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Redis\Connections\Connection;
use Modulith\Config\RedisStream;
use Modulith\Contracts\RedeliversEnvelopes;
use Modulith\Contracts\Transport;
use Modulith\Contracts\TrimsStreams;
use Modulith\Data\Envelope;
use Throwable;

/**
 * Redis Streams, straight on Illuminate\Redis — no third-party event package, because the wire
 * format has to be ours for transports to stay interchangeable.
 *
 * One stream per EMITTING module (`{prefix}{module}`), one consumer group per CONSUMING module:
 * each consumer keeps its own cursor, so plugging a new one disturbs nobody. Delivery is
 * at-least-once — unacknowledged entries come back, which is exactly what the consumption guard
 * is for.
 */
final class RedisStreamTransport implements RedeliversEnvelopes, Transport, TrimsStreams
{
    private const string OWN_PENDING = '0';

    private const string NEW_ENTRIES = '>';

    private const int MAX_BACKOFF_SECONDS = 60;

    private bool $listening = true;

    public function __construct(
        private readonly Redis $redis,
        private readonly RedisStream $config,
    ) {}

    public function publish(Envelope $envelope): void
    {
        $this->connection()->command('xadd', [$this->stream($envelope->emitter), '*', ['envelope' => $envelope->toJson()]]);
    }

    public function trim(string $channel): int
    {
        $stream = $this->stream($channel);
        $floor = null;

        try {
            /** @var list<array<string, mixed>>|false $groups */
            $groups = $this->connection()->command('xinfo', ['GROUPS', $stream]);
        } catch (Throwable) {
            return 0; // no such stream yet
        }

        foreach ($groups ?: [] as $group) {
            /** @var array{0?: int, 1?: string|null}|false $pending */
            $pending = $this->connection()->command('xpending', [$stream, (string) $group['name']]);
            $oldest = is_array($pending) && ($pending[0] ?? 0) > 0 ? (string) $pending[1] : (string) $group['last-delivered-id'];

            $floor = $floor === null || self::compareIds($oldest, $floor) < 0 ? $oldest : $floor;
        }

        if ($floor === null) {
            return 0; // no consumer group yet: nobody has read anything
        }

        // phpredis: xTrim(key, threshold, approximate, minid)
        return (int) $this->connection()->command('xtrim', [$stream, $floor, false, true]);
    }

    private static function compareIds(string $a, string $b): int
    {
        [$aMs, $aSeq] = array_map(intval(...), explode('-', $a.'-0'));
        [$bMs, $bSeq] = array_map(intval(...), explode('-', $b.'-0'));

        return [$aMs, $aSeq] <=> [$bMs, $bSeq];
    }

    public function consume(string $consumer, array $channels, callable $handle): void
    {
        $streams = array_map(fn (string $channel): string => $this->stream($channel), $channels);

        foreach ($streams as $stream) {
            $this->ensureGroup($stream, $consumer);
        }

        $name = $consumer.':'.(gethostname() ?: 'unknown-host');
        $failures = 0;

        while ($this->listening) {
            $this->reclaim($streams, $consumer, $name, $handle);

            // Own pending entries first: a failed one is retried before anything later is read.
            $read = $this->read($streams, $consumer, $name, self::OWN_PENDING) ?: $this->read($streams, $consumer, $name, self::NEW_ENTRIES);

            foreach ($read as $stream => $entries) {
                if (! $this->handleEntries((string) $stream, $entries, $consumer, $handle)) {
                    $failures++;
                    sleep(min(self::MAX_BACKOFF_SECONDS, 2 ** min($failures, 6)));

                    continue 2;
                }
            }

            $failures = 0;
        }
    }

    /**
     * @param  list<string>  $streams
     * @return array<string, array<string, array<string, string>>>
     */
    private function read(array $streams, string $group, string $name, string $from): array
    {
        /** @var array<string, array<string, array<string, string>>>|null|false $read */
        $read = $this->connection()->command('xreadgroup', [
            $group,
            $name,
            array_fill_keys($streams, $from),
            $this->config->getCount(),
            $this->config->getBlockMs(),
        ]);

        return is_array($read) ? array_filter($read) : [];
    }

    public function stop(): void
    {
        $this->listening = false;
    }

    /**
     * Entries left pending by a consumer that died mid-handling: claim them back after
     * `claimAfterMs` and replay. Nothing is lost when a process is killed.
     *
     * @param  list<string>  $streams
     * @param  callable(Envelope): void  $handle
     */
    private function reclaim(array $streams, string $group, string $name, callable $handle): void
    {
        foreach ($streams as $stream) {
            /** @var array<int, mixed>|null $claimed */
            $claimed = $this->connection()->command('xautoclaim', [
                $stream, $group, $name, $this->config->getClaimAfterMs(), '0-0', $this->config->getCount(),
            ]);

            // phpredis returns [nextCursor, entries, deleted]; predis the same shape.
            $entries = is_array($claimed) ? ($claimed[1] ?? []) : [];

            if (is_array($entries) && $entries !== []) {
                $this->handleEntries($stream, $entries, $group, $handle);
            }
        }
    }

    /**
     * Stops at the first failure and leaves it unacknowledged: handling the next entry would apply
     * it ahead of the one that failed.
     *
     * @param  array<string, array<string, string>>  $entries
     * @param  callable(Envelope): void  $handle
     */
    private function handleEntries(string $stream, array $entries, string $group, callable $handle): bool
    {
        foreach ($entries as $id => $fields) {
            try {
                $handle(Envelope::fromJson($fields['envelope'] ?? '{}'));
            } catch (Throwable $e) {
                report($e);

                return false;
            }

            $this->connection()->command('xack', [$stream, $group, [$id]]);
        }

        return true;
    }

    private function ensureGroup(string $stream, string $group): void
    {
        try {
            // From the start of the stream: a consumer plugged in late still sees what was announced before it.
            $this->connection()->command('xgroup', ['CREATE', $stream, $group, '0', true]);
        } catch (Throwable) {
            // BUSYGROUP: the group already exists, which is the normal case.
        }
    }

    private function stream(string $module): string
    {
        return $this->config->getPrefix().$module;
    }

    private function connection(): Connection
    {
        /** @var Connection */
        return $this->redis->connection($this->config->getConnection());
    }
}
