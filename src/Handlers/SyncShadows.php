<?php

declare(strict_types=1);

namespace Modulith\Handlers;

use Modulith\Contracts\Handler;
use Modulith\Contracts\Idempotent;
use Modulith\Services\ShadowRegistry;

/** Writes an announced source row into every local copy of that source table. */
final readonly class SyncShadows implements Handler, Idempotent
{
    public function __construct(private ShadowRegistry $catalog) {}

    public function handle(string $name, array $payload): void
    {
        /** @var array<string, mixed> $attributes */
        $attributes = (array) ($payload['attributes'] ?? []);
        $key = $payload['key'];

        foreach ($this->catalog->shadowsOf((string) $payload['source']) as $shadow) {
            $shadow::sync(is_int($key) ? $key : (string) $key, $attributes);
        }
    }
}
