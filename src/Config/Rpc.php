<?php

declare(strict_types=1);

namespace Modulith\Config;

use Illuminate\Contracts\Config\Repository;
use Modulith\Exceptions\ConfigurationException;

/** The modulith.rpc.* settings, and each module's host; read live, never snapshotted. */
final readonly class Rpc
{
    public function __construct(private Repository $config) {}

    public function hasHost(string $module): bool
    {
        return $this->config->get("modulith.modules.{$module}.host") !== null;
    }

    public function getHost(string $module): string
    {
        $host = $this->host($module);

        return (string) (is_array($host) ? ($host['url'] ?? '') : $host);
    }

    /** The transport a module's calls travel on: its host's `transport`, or modulith.rpc.transport. */
    public function getTransportOf(string $module): string
    {
        $host = $this->host($module);

        return (string) (is_array($host) && isset($host['transport']) ? $host['transport'] : $this->getDefaultTransport());
    }

    public function getDefaultTransport(): string
    {
        return (string) $this->config->get('modulith.rpc.transport', 'http');
    }

    /** @return array<string, mixed> */
    public function getTransport(string $name): array
    {
        /** @var array<string, array<string, mixed>> $transports */
        $transports = (array) $this->config->get('modulith.rpc.transports', []);

        return $transports[$name] ?? throw ConfigurationException::unknownRpcTransport($name);
    }

    /** @return string|array<string, mixed> */
    private function host(string $module): string|array
    {
        /** @var string|array<string, mixed>|null $host */
        $host = $this->config->get("modulith.modules.{$module}.host");

        return $host ?? throw ConfigurationException::missingRpcHost($module);
    }

    public function getSecret(): string
    {
        return (string) $this->config->get('modulith.rpc.secret', '');
    }

    /** Null is the application's default cache store. */
    public function getCacheStore(): ?string
    {
        $store = $this->config->get('modulith.rpc.cache');

        return is_string($store) && $store !== '' ? $store : null;
    }

    public function getSignatureTtl(): int
    {
        return (int) $this->config->get('modulith.rpc.signature_ttl', 30);
    }
}
