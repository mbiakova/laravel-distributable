<?php

declare(strict_types=1);

namespace Modulith\Events;

/** A module keeping a copy asks the owner of the source table to announce every row it holds. */
final class ShadowWanted extends Event
{
    public const string NAME = 'modulith.shadow.wanted';

    public function __construct(
        private readonly string $keeperModule,
        private readonly string $source,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    /** @return array{source: string} */
    public function payload(): array
    {
        return ['source' => $this->source];
    }

    public function emitter(): string
    {
        return $this->keeperModule;
    }
}
