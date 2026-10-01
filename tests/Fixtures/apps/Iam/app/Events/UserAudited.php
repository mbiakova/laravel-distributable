<?php

declare(strict_types=1);

namespace Apps\Iam\Events;

use Modulith\Events\Event;

/** Travels on the `audit` stream the module declares in its config/modulith.php. */
final class UserAudited extends Event
{
    public function __construct(private readonly int $id) {}

    public function name(): string
    {
        return 'iam.user.audited';
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return ['id' => $this->id];
    }

    public function stream(): string
    {
        return 'audit';
    }
}
