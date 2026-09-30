<?php

declare(strict_types=1);

namespace Modulith\Contracts;

/** A transport that keeps envelopes after delivery, and drops only those every consumer acknowledged. */
interface TrimsStreams
{
    /** @return int how many entries were dropped from this emitter's stream */
    public function trim(string $channel): int;
}
