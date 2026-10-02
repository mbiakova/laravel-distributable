<?php

declare(strict_types=1);

namespace Modulith\Services\Rpc;

use Closure;
use Illuminate\Contracts\Container\Container;
use Modulith\Config\Rpc;
use Modulith\Contracts\Rpc\RpcTransport;
use Modulith\Data\Module;
use Modulith\Exceptions\ConfigurationException;
use Modulith\Services\Modules\ModuleRegistry;
use Modulith\Transports\Rpc\HttpRpcTransport;
use Modulith\Transports\Rpc\LocalRpcTransport;

/**
 * Routes each call: to the module itself when it runs in this process, otherwise to the named
 * transport of modulith.rpc.transports its host names. extend('grpc', fn ($app, array $config,
 * string $name) => …) registers any driver.
 */
final class RpcTransportManager implements RpcTransport
{
    /** @var array<string, Closure(Container, array<string, mixed>, string): RpcTransport> */
    private array $creators = [];

    /** @var array<string, RpcTransport> */
    private array $transports = [];

    public function __construct(
        private readonly Container $container,
        private readonly ModuleRegistry $registry,
    ) {}

    public function invoke(Module $module, string $contract, string $method, array $arguments = []): mixed
    {
        $transport = $this->registry->isLocal($module->name)
            ? $this->container->make(LocalRpcTransport::class)
            : $this->transport($this->config()->getTransportOf($module->name));

        return $transport->invoke($module, $contract, $method, $arguments);
    }

    public function transport(?string $name = null): RpcTransport
    {
        $name ??= $this->config()->getDefaultTransport();

        return $this->transports[$name] ??= $this->create($name, $this->config()->getTransport($name));
    }

    /** From the running application, like ModuleContext's: Octane serves each request from its own copy. */
    private function config(): Rpc
    {
        return \Illuminate\Container\Container::getInstance()->make(Rpc::class);
    }

    /** @param Closure(Container, array<string, mixed>, string): RpcTransport $creator */
    public function extend(string $driver, Closure $creator): static
    {
        $this->creators[$driver] = $creator;
        $this->transports = [];

        return $this;
    }

    /** @param array<string, mixed> $config */
    private function create(string $name, array $config): RpcTransport
    {
        $driver = (string) ($config['driver'] ?? '');

        if (isset($this->creators[$driver])) {
            return ($this->creators[$driver])($this->container, $config, $name);
        }

        return match ($driver) {
            'http' => $this->container->make(HttpRpcTransport::class),
            default => throw ConfigurationException::unknownRpcDriver($driver, $name),
        };
    }
}
