<?php

declare(strict_types=1);

namespace Foundation\Iam\Services;

use Modulith\Services\Rpc\RpcService;

final class TokenRpcService extends RpcService
{
    public int $calls = 0;

    /** @return array{token: string, expires_in: int}|null */
    public function token(int $expiresIn): ?array
    {
        return $this->rememberUntil('iam:token', function () use ($expiresIn): array {
            $this->calls++;

            return ['token' => 't'.$this->calls, 'expires_in' => $expiresIn];
        }, fn (array $token): int => $token['expires_in']);
    }

    public function drop(): void
    {
        $this->forget('iam:token');
    }
}
