<?php

declare(strict_types=1);

namespace Modulith\Contracts\Stream;

use Modulith\Events\Event;

/**
 * The single seam modules emit events through. The binding decides what emitting means —
 * straight to the transport, or through the outbox — and module code never knows which.
 */
interface Bus
{
    public function emit(Event $event): void;
}
