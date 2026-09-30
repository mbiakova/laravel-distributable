<?php

declare(strict_types=1);

namespace Modules\Iam\Services;

use Modules\Iam\Contracts\IamService;
use Modulith\Services\RemoteService;

final class RemoteIamService extends RemoteService implements IamService
{
    public function findUser(int $id): ?array
    {
        /** @var array{id: int, name: string}|null */
        return $this->remember("iam:user:{$id}", 3600, fn (): mixed => $this->call('users', 'find', ['id' => $id]));
    }
}
