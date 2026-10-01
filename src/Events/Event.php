<?php

declare(strict_types=1);

namespace Modulith\Events;

/**
 * Base for module events: a concrete event returns its name from the module's event-name enum
 * and its typed payload as an array. Nothing else — identity, emitting module, propagated
 * context and timestamp are the Envelope's business, so the payload stays business only.
 */
abstract class Event
{
    abstract public function name(): string;

    /** @return array<string, mixed> */
    abstract public function payload(): array;

    /** The emitting module, when it is not the one the event class lives in. */
    public function emitter(): ?string
    {
        return null;
    }

    /** @return list<string> the only modules that handle it; empty for every module */
    public function recipients(): array
    {
        return [];
    }

    /** The stream of modulith.events.streams it travels on; null for the default one. */
    public function stream(): ?string
    {
        return null;
    }
}
