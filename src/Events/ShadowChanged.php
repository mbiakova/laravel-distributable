<?php

declare(strict_types=1);

namespace Modulith\Events;

/** A source row as it now stands, announced to the modules keeping a copy of it. */
final class ShadowChanged extends Event
{
    public const string NAME = 'modulith.shadow.changed';

    /** @param array<string, mixed> $attributes */
    public function __construct(
        private readonly string $sourceModule,
        private readonly string $source,
        private readonly int|string $key,
        private readonly array $attributes,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    /** @return array{source: string, key: int|string, attributes: array<string, mixed>} */
    public function payload(): array
    {
        return ['source' => $this->source, 'key' => $this->key, 'attributes' => $this->attributes];
    }

    public function emitter(): string
    {
        return $this->sourceModule;
    }
}
