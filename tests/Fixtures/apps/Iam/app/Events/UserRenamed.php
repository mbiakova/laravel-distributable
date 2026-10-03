<?php

declare(strict_types=1);

namespace Apps\Iam\Events;

use Foundation\Iam\Events\UserRenamedPayload;
use Microservices\Events\Event;

final class UserRenamed extends Event
{
    public function __construct(private readonly int $id, private readonly string $fullName) {}

    public function name(): string
    {
        return UserRenamedPayload::NAME;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return ['id' => $this->id, 'full_name' => $this->fullName, 'locale' => 'fr'];
    }

    public function version(): int
    {
        return UserRenamedPayload::version();
    }
}
