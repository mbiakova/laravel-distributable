<?php

declare(strict_types=1);

namespace Modules\Iam\Contracts;

interface IamService
{
    /** @return array{id: int, name: string}|null */
    public function findUser(int $id): ?array;
}
