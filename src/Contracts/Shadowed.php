<?php

declare(strict_types=1);

namespace Modulith\Contracts;

/** A model other modules keep a copy of; implemented by the ShadowSource trait. */
interface Shadowed
{
    public function announceShadow(bool $deleted = false): void;

    /** Re-announces every row; returns how many went out. */
    public static function announceAll(int $chunk = 500): int;

    /** @return list<string> */
    public function shadowedFields(): array;
}
