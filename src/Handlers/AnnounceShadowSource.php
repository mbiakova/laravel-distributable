<?php

declare(strict_types=1);

namespace Modulith\Handlers;

use Modulith\Contracts\Stream\Handler;
use Modulith\Contracts\Stream\Idempotent;
use Modulith\Services\Shadows\ShadowRegistry;

/** Answers a copy that asks again: every row is re-announced to the asking module alone. */
final readonly class AnnounceShadowSource implements Handler, Idempotent
{
    public function __construct(private ShadowRegistry $catalog) {}

    public function handle(string $name, array $payload): void
    {
        $this->catalog->sourceOf((string) $payload['source'])?->announceAll(keepers: [(string) $payload['keeper']]);
    }
}
