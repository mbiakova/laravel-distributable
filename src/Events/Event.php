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
}
