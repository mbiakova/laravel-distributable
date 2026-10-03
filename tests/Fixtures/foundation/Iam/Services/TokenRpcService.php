<?php

declare(strict_types=1);

namespace Foundation\Iam\Services;

use Microservices\Services\Rpc\RpcService;

final class TokenRpcService extends RpcService
{
    public int $calls = 0;

    /** @return array{token: string, expires_in: int}|null */
    public function token(int $expiresIn): ?array
    {
        return $this->readThroughUntil('iam:token', function () use ($expiresIn): array {
            $this->calls++;

            return ['token' => 't'.$this->calls, 'expires_in' => $expiresIn];
        }, fn (array $raw): array => ['token' => (string) $raw['token'], 'expires_in' => (int) $raw['expires_in']], fn (array $token): int => $token['expires_in']);
    }

    /** @return array{id: int}|null */
    public function shaped(callable $fetch): ?array
    {
        return $this->readThrough('iam:shaped', self::DEFAULT_TTL, $fetch(...), fn (array $raw): array => ['id' => $raw['id']]);
    }

    public function drop(): void
    {
        $this->forget('iam:token');
    }
}
