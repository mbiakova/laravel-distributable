<?php

declare(strict_types=1);

namespace Foundation\Iam\Services;

use Foundation\Iam\Contracts\IamService;
use Modulith\Services\Rpc\RpcService;

final class IamRpcService extends RpcService implements IamService
{
    public function findUser(int $id): ?array
    {
        return $this->readThrough(
            "iam:user:{$id}",
            self::DEFAULT_TTL,
            fn (): mixed => $this->call('users', 'find', ['id' => $id]),
            fn (array $raw): array => ['id' => (int) $raw['id'], 'name' => (string) $raw['name']],
        );
    }
}
