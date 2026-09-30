<?php

declare(strict_types=1);

namespace Modulith\Handlers;

use Modulith\Contracts\Handler;
use Modulith\Contracts\Idempotent;
use Modulith\Services\ShadowRegistry;

/** Answers a copy that asks again: the local source model re-announces every row it holds. */
final readonly class AnnounceShadowSource implements Handler, Idempotent
{
    public function __construct(private ShadowRegistry $catalog) {}

    public function handle(string $name, array $payload): void
    {
        $this->catalog->sourceOf((string) $payload['source'])?->announceAll();
    }
}
