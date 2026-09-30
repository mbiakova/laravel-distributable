<?php

declare(strict_types=1);

namespace Modulith\Events;

/** A module keeping a copy asks the owner of the source table to announce every row, to it alone. */
final class ShadowWanted extends Event
{
    public const string NAME = 'modulith.shadow.wanted';

    public function __construct(
        private readonly string $keeperModule,
        private readonly string $ownerModule,
        private readonly string $source,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    /** @return array{source: string, keeper: string} */
    public function payload(): array
    {
        return ['source' => $this->source, 'keeper' => $this->keeperModule];
    }

    public function emitter(): string
    {
        return $this->keeperModule;
    }

    /** @return list<string> */
    public function recipients(): array
    {
        return [$this->ownerModule];
    }
}
