<?php

declare(strict_types=1);

namespace Foundation\Iam\Contracts;

interface IamService
{
    /** @return array{id: int, name: string}|null */
    public function findUser(int $id): ?array;

    public function traceId(): ?string;

    public function defaultConnection(): string;

    public function countUsers(): int;

    public function renameUser(int $id, string $name): void;
}
