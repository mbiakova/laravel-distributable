<?php

declare(strict_types=1);

namespace Apps\Iam\Events;

use Microservices\Events\Event;

final class UserRegistered extends Event
{
    public function __construct(
        private readonly int $id,
        private readonly string $userName,
    ) {}

    public function name(): string
    {
        return IamEvent::UserRegistered->value;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return ['id' => $this->id, 'name' => $this->userName];
    }
}
