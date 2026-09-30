<?php

declare(strict_types=1);

namespace Foundation\Iam\Contracts;

interface IamService
{
    /** @return array{id: int, name: string}|null */
    public function findUser(int $id): ?array;
}
