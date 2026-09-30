<?php

declare(strict_types=1);

namespace Apps\Iam\Events;

use Modulith\Events\Event;

/** Emitted by the module, listened to by nobody — the no-subscriber path. */
final class UserIgnored extends Event
{
    public function __construct(private readonly int $id) {}

    public function name(): string
    {
        return 'iam.user.ignored';
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return ['id' => $this->id];
    }
}
