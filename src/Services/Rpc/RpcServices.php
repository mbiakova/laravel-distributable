<?php

declare(strict_types=1);

namespace Modulith\Services\Rpc;

/** Each foundation contract the application's FoundationServiceProvider maps, with its module and its RpcService. */
final class RpcServices
{
    /** @var array<class-string, array{module: string|null, rpc: class-string}> */
    private array $services = [];

    /** @param array<class-string, array{module: string|null, rpc: class-string}> $services */
    public function add(array $services): void
    {
        $this->services = [...$this->services, ...$services];
    }

    /** @return array<class-string, array{module: string|null, rpc: class-string}> */
    public function all(): array
    {
        return $this->services;
    }
}
