<?php

declare(strict_types=1);

namespace Apps\Iam\Services;

use Foundation\Iam\Contracts\IamService as Contract;

final class IamService implements Contract
{
    public function findUser(int $id): ?array
    {
        return $id === 1 ? ['id' => 1, 'name' => 'ada'] : null;
    }
}
