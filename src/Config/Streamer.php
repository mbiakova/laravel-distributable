<?php

declare(strict_types=1);

namespace Modulith\Config;

use Illuminate\Contracts\Config\Repository;

/** The streamer.* settings, typed and defaulted; read live, never snapshotted. */
final readonly class Streamer
{
    public function __construct(private Repository $config) {}

    public function getTransport(): string
    {
        return (string) $this->config->get('streamer.transport', 'redis');
    }

    public function getOutbox(): bool
    {
        return (bool) $this->config->get('streamer.outbox', false);
    }

    public function getGuard(): ?bool
    {
        $guard = $this->config->get('streamer.guard');

        return $guard === null ? null : (bool) $guard;
    }

    /** @return list<class-string> */
    public function getHandlers(string $event): array
    {
        /** @var array<string, list<class-string>> $listen */
        $listen = $this->config->get('streamer.listen', []);

        // Direct key access: event names carry dots, which config dot-notation would split.
        return $listen[$event] ?? [];
    }

    /** @return list<string> */
    public function getPropagate(): array
    {
        return array_values(array_map(strval(...), (array) $this->config->get('streamer.propagate', [])));
    }
}
