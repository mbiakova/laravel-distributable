<?php

declare(strict_types=1);

namespace Modulith\Services\Stream;

use Illuminate\Support\Facades\Context;
use Modulith\Config\Streamer;
use Modulith\Data\Envelope;
use Modulith\Events\Event;
use Modulith\Exceptions\ModuleException;
use Modulith\Services\Modules\ModuleRegistry;

/** Stamps an event with its emitting module and the propagated context — the only place that does. */
final class EnvelopeFactory
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly Streamer $config,
    ) {}

    public function for(Event $event): Envelope
    {
        $emitter = $event->emitter()
            ?? ($this->registry->forClass($event::class) ?? throw ModuleException::outsideModule($event::class))->name;

        return Envelope::for(
            event: $event,
            emitter: $emitter,
            headers: array_filter(Context::only($this->config->getPropagate()), static fn (mixed $value): bool => $value !== null),
            stream: $event->stream() ?? $this->config->getDefaultStream(),
        );
    }
}
