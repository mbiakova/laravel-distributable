<?php

declare(strict_types=1);

namespace Modulith\Contracts;

/**
 * A module's reaction to an event, declared in its config/streamer.php listen map. The signature
 * is transport-neutral — a distributed consumer only ever has the name and the raw payload, so
 * the in-process driver hands over the same thing.
 */
interface Handler
{
    /** @param array<string, mixed> $payload */
    public function handle(string $name, array $payload): void;
}
