<?php

declare(strict_types=1);

namespace Modulith\Config;

use Illuminate\Contracts\Config\Repository;
use Modulith\Exceptions\ConfigurationException;

/** The modulith.events.* settings, typed and defaulted; read live, never snapshotted. */
final readonly class Streamer
{
    public function __construct(private Repository $config) {}

    public function getDefaultStream(): string
    {
        return (string) $this->config->get('modulith.events.stream', 'default');
    }

    /** @return array<string, mixed> */
    public function getStream(string $name): array
    {
        /** @var array<string, array<string, mixed>> $streams */
        $streams = $this->config->get('modulith.events.streams', []);

        return $streams[$name] ?? throw ConfigurationException::unknownStream($name);
    }

    public function getRedisStream(string $name): RedisStream
    {
        return new RedisStream($this->config, $name);
    }

    public function getQueueStream(string $name): QueueStream
    {
        return new QueueStream($this->config, $name);
    }

    public function usesOutbox(string $stream): bool
    {
        return (bool) $this->config->get("modulith.events.streams.{$stream}.outbox", false);
    }

    public function getGuard(): ?bool
    {
        $guard = $this->config->get('modulith.events.guard');

        return $guard === null ? null : (bool) $guard;
    }

    /** @return list<class-string> */
    public function getHandlers(string $event): array
    {
        /** @var array<string, list<class-string>> $listen */
        $listen = $this->config->get('modulith.events.listen', []);

        // Direct key access: event names carry dots, which config dot-notation would split.
        return $listen[$event] ?? [];
    }

    /** @return list<string> */
    public function getPropagate(): array
    {
        return array_values(array_map(strval(...), (array) $this->config->get('modulith.events.propagate', [])));
    }
}
