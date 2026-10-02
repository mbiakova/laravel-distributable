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
            fn (): mixed => $this->call('findUser', ['id' => $id]),
            fn (array $raw): array => ['id' => (int) $raw['id'], 'name' => (string) $raw['name']],
        );
    }

    public function traceId(): ?string
    {
        $traceId = $this->call('traceId');

        return is_string($traceId) ? $traceId : null;
    }

    public function defaultConnection(): string
    {
        return (string) $this->call('defaultConnection');
    }

    public function countUsers(): int
    {
        return (int) $this->call('countUsers');
    }

    public function renameUser(int $id, string $name): void
    {
        $this->call('renameUser', ['id' => $id, 'name' => $name]);
    }
}
