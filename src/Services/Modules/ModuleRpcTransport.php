<?php

declare(strict_types=1);

namespace Modulith\Services\Modules;

use Illuminate\Container\Container;
use Microservices\Contracts\Rpc\RpcTransport;
use Microservices\Services\Rpc\LocalServices;
use Microservices\Services\Rpc\RpcTransportManager;

/** A call to a module this process runs is a direct method call; any other goes out on the module's transport. */
final readonly class ModuleRpcTransport implements RpcTransport
{
    public function __construct(
        private LocalServices $services,
        private RpcTransportManager $remote,
    ) {}

    public function invoke(string $service, string $contract, string $method, array $arguments = []): mixed
    {
        // From the running application: Octane serves each request from its own copy.
        $registry = Container::getInstance()->make(ModuleRegistry::class);

        return $registry->find($service) !== null && $registry->isLocal($service)
            ? $this->services->call($service, $contract, $method, $arguments)
            : $this->remote->invoke($service, $contract, $method, $arguments);
    }
}
