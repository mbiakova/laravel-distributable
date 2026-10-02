<?php

declare(strict_types=1);

namespace Apps\Iam\Services;

use Foundation\Iam\Contracts\IamService as Contract;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use LogicException;

final class IamService implements Contract
{
    public function findUser(int $id): ?array
    {
        return $id === 1 ? ['id' => 1, 'name' => 'ada'] : null;
    }

    public function traceId(): ?string
    {
        $traceId = Context::get('trace_id');

        return is_string($traceId) ? $traceId : null;
    }

    public function defaultConnection(): string
    {
        return DB::getDefaultConnection();
    }

    public function countUsers(): int
    {
        return DB::table('iam_users')->count();
    }

    public function renameUser(int $id, string $name): void
    {
        DB::transaction(function () use ($id, $name): void {
            DB::table('iam_users')->where('id', $id)->update(['name' => $name]);

            if (DB::transactionLevel() !== 1) {
                throw new LogicException('The transaction is not open on the default connection.');
            }
        });
    }
}
