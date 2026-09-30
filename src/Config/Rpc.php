<?php

declare(strict_types=1);

namespace Modulith\Config;

use Illuminate\Contracts\Config\Repository;
use Modulith\Exceptions\ConfigurationException;

/** The rpc.* settings of the calls between modules; read live, never snapshotted. */
final readonly class Rpc
{
    public function __construct(private Repository $config) {}

    /** @return array<class-string, array{module: string, rpc: class-string}> */
    public function getServices(): array
    {
        /** @var array<class-string, array{module: string, rpc: class-string}> */
        return (array) $this->config->get('rpc.services', []);
    }

    public function hasHost(string $module): bool
    {
        return array_key_exists($module, (array) $this->config->get('rpc.hosts', []));
    }

    public function getHost(string $module): string
    {
        $host = $this->host($module);

        return (string) (is_array($host) ? ($host['url'] ?? '') : $host);
    }

    /** The transport a module's calls travel on: its host's `transport`, or rpc.default. */
    public function getTransportOf(string $module): string
    {
        $host = $this->host($module);

        return (string) (is_array($host) && isset($host['transport']) ? $host['transport'] : $this->getDefaultTransport());
    }

    public function getDefaultTransport(): string
    {
        return (string) $this->config->get('rpc.default', 'http');
    }

    /** @return array<string, mixed> */
    public function getTransport(string $name): array
    {
        /** @var array<string, array<string, mixed>> $transports */
        $transports = (array) $this->config->get('rpc.transports', []);

        return $transports[$name] ?? throw ConfigurationException::unknownRpcTransport($name);
    }

    /** @return string|array<string, mixed> */
    private function host(string $module): string|array
    {
        /** @var array<string, string|array<string, mixed>> $hosts */
        $hosts = (array) $this->config->get('rpc.hosts', []);

        return $hosts[$module] ?? throw ConfigurationException::missingRpcHost($module);
    }

    public function getSecret(): string
    {
        return (string) $this->config->get('rpc.secret', '');
    }

    public function getSignatureTtl(): int
    {
        return (int) $this->config->get('rpc.signature_ttl', 30);
    }
}
