<?php

declare(strict_types=1);

namespace Modulith\Transports\Stream;

use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Redis\Connections\Connection;
use Modulith\Config\RedisStream;
use Modulith\Contracts\Stream\RedeliversEnvelopes;
use Modulith\Contracts\Stream\TracksAcknowledgements;
use Modulith\Contracts\Stream\Transport;
use Modulith\Contracts\Stream\TrimsStreams;
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
final class RedisStreamTransport implements RedeliversEnvelopes, TracksAcknowledgements, Transport, TrimsStreams
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
        $this->publishTracked($envelope);
    }

    public function publishTracked(Envelope $envelope): string
    {
        return (string) $this->connection()->command('xadd', [$this->stream($envelope->emitter), '*', ['envelope' => $envelope->toJson()]]);
    }

    /** Every group must have been delivered the entry, and hold no pending entry at or below it. */
    public function isAcknowledged(string $channel, string $entryId): bool
    {
        $stream = $this->stream($channel);

        try {
            /** @var list<array<string, mixed>>|false $groups */
            $groups = $this->connection()->command('xinfo', ['GROUPS', $stream]);
        } catch (Throwable) {
            return false;
        }

        foreach ($groups ?: [] as $group) {
            /** @var array{0?: int, 1?: string|null}|false $pending */
            $pending = $this->connection()->command('xpending', [$stream, (string) $group['name']]);
            $delivered = self::compareIds($entryId, (string) $group['last-delivered-id']) <= 0;
            $stillPending = is_array($pending) && ($pending[0] ?? 0) > 0 && self::compareIds($entryId, (string) $pending[1]) >= 0;

            if (! $delivered || $stillPending) {
                return false;
            }
        }

        return ($groups ?: []) !== [];
    }

    public function trim(string $channel): int
    {
        $stream = $this->stream($channel);
        $floor = $this->floor($stream);

        if ($floor === null) {
            return 0;
        }

        // phpredis: xTrim(key, threshold, approximate, minid)
        return (int) $this->connection()->command('xtrim', [$stream, $floor, false, true]);
    }

    /** Every entry below it is acknowledged by every group; null while no group reads the stream. */
    private function floor(string $stream): ?string
    {
        $floor = null;

        try {
            /** @var list<array<string, mixed>>|false $groups */
            $groups = $this->connection()->command('xinfo', ['GROUPS', $stream]);
        } catch (Throwable) {
            return null; // no such stream yet
        }

        foreach ($groups ?: [] as $group) {
            /** @var array{0?: int, 1?: string|null}|false $pending */
            $pending = $this->connection()->command('xpending', [$stream, (string) $group['name']]);
            $oldest = is_array($pending) && ($pending[0] ?? 0) > 0 ? (string) $pending[1] : (string) $group['last-delivered-id'];

            $floor = $floor === null || self::compareIds($oldest, $floor) < 0 ? $oldest : $floor;
        }

        return $floor;
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
        $this->listening = true;

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

        if (! is_array($read)) {
            return [];
        }

        // Replies name the stream with the client prefix, which every later command adds again.
        $client = $this->connection()->client();
        $prefix = $client instanceof \Redis ? (string) $client->getOption(\Redis::OPT_PREFIX) : '';
        $unprefixed = [];

        foreach (array_filter($read) as $stream => $entries) {
            $unprefixed[$prefix !== '' && str_starts_with((string) $stream, $prefix) ? substr((string) $stream, strlen($prefix)) : (string) $stream] = $entries;
        }

        return $unprefixed;
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
            $entries = $this->autoclaim($stream, $group, $name);

            if ($entries !== []) {
                $this->handleEntries($stream, $entries, $group, $handle);
            }
        }
    }

    /**
     * Sent raw: phpredis 6.1's xAutoClaim() loses the connection. The raw reply lists entries as
     * [id, [field, value, …]].
     *
     * @return array<string, array<string, string>>
     */
    private function autoclaim(string $stream, string $group, string $name): array
    {
        $client = $this->connection()->client();
        $arguments = [$stream, $group, $name, (string) $this->config->getClaimAfterMs(), '0-0', 'COUNT', (string) $this->config->getCount()];

        $reply = $client instanceof \Redis
            ? $client->rawCommand('XAUTOCLAIM', $client->_prefix($stream), ...array_slice($arguments, 1))
            : $this->connection()->command('xautoclaim', $arguments);

        $entries = [];

        foreach (is_array($reply) && is_array($reply[1] ?? null) ? $reply[1] : [] as $entry) {
            if (is_array($entry) && isset($entry[0]) && is_array($entry[1] ?? null)) {
                $fields = [];

                for ($i = 0; $i + 1 < count($entry[1]); $i += 2) {
                    $fields[(string) $entry[1][$i]] = (string) $entry[1][$i + 1];
                }

                $entries[(string) $entry[0]] = $fields;
            }
        }

        return $entries;
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
