<?php

declare(strict_types=1);

namespace Modules\Iam\Services;

use Modules\Iam\Contracts\IamService;

final class LocalIamService implements IamService
{
    public function findUser(int $id): ?array
    {
        return $id === 1 ? ['id' => 1, 'name' => 'ada'] : null;
    }
}
