<?php

declare(strict_types=1);

namespace Foundation\Iam\Services;

use Foundation\Iam\Contracts\IamService;
use Modulith\Services\Rpc\RpcService;

final class IamRpcService extends RpcService implements IamService
{
    public function findUser(int $id): ?array
    {
        /** @var array{id: int, name: string}|null */
        return $this->remember("iam:user:{$id}", 3600, fn (): mixed => $this->call('users', 'find', ['id' => $id]));
    }
}
