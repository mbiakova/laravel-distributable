<?php

declare(strict_types=1);

namespace Modulith\Services\Rpc;

use Closure;
use Illuminate\Contracts\Container\Container;
use Modulith\Config\Rpc;
use Modulith\Contracts\Rpc\RpcTransport;
use Modulith\Data\Module;
use Modulith\Exceptions\ConfigurationException;
use Modulith\Transports\Rpc\HttpRpcTransport;

/**
 * Resolves a named transport of rpc.transports, and routes each call to the one its module's host
 * names: extend('grpc', fn ($app, array $config, string $name) => …) registers any driver.
 */
final class RpcTransportManager implements RpcTransport
{
    /** @var array<string, Closure(Container, array<string, mixed>, string): RpcTransport> */
    private array $creators = [];

    /** @var array<string, RpcTransport> */
    private array $transports = [];

    public function __construct(
        private readonly Container $container,
        private readonly Rpc $config,
    ) {}

    public function invoke(Module $module, string $resource, string $operation, array $payload = []): mixed
    {
        return $this->transport($this->config->getTransportOf($module->name))->invoke($module, $resource, $operation, $payload);
    }

    public function transport(?string $name = null): RpcTransport
    {
        $name ??= $this->config->getDefaultTransport();

        return $this->transports[$name] ??= $this->create($name, $this->config->getTransport($name));
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
