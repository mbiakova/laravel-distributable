<?php

declare(strict_types=1);

namespace Modulith\Transports\Rpc;

use Modulith\Contracts\Rpc\RpcTransport;
use Modulith\Data\Module;
use Modulith\Services\Rpc\LocalServices;

/** For a module running in this process: no network, the same answer a remote call gets. */
final readonly class LocalRpcTransport implements RpcTransport
{
    public function __construct(private LocalServices $services) {}

    public function invoke(Module $module, string $contract, string $method, array $arguments = []): mixed
    {
        return $this->services->call($module, $contract, $method, $arguments);
    }
}
