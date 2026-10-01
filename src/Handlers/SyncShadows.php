<?php

declare(strict_types=1);

namespace Modulith\Handlers;

use Modulith\Contracts\Stream\Handler;
use Modulith\Contracts\Stream\Idempotent;
use Modulith\Services\Modules\ModuleContext;
use Modulith\Services\Shadows\ShadowRegistry;

/** Writes an announced source row into the copies of the consuming module, or of every local one. */
final readonly class SyncShadows implements Handler, Idempotent
{
    public function __construct(
        private ShadowRegistry $catalog,
        private ModuleContext $context,
    ) {}

    public function handle(string $name, array $payload): void
    {
        /** @var array<string, mixed> $attributes */
        $attributes = (array) ($payload['attributes'] ?? []);
        $key = $payload['key'];

        foreach ($this->catalog->shadowsOf((string) $payload['source'], $this->context->current()) as $shadow) {
            $shadow::sync(is_int($key) ? $key : (string) $key, $attributes);
        }
    }
}
