<?php

declare(strict_types=1);

namespace Modulith\Config;

use Illuminate\Contracts\Config\Repository;
use Modulith\Exceptions\ConfigurationException;

/** The rpc.* settings of the calls between modules; read live, never snapshotted. */
final readonly class Rpc
{
    public function __construct(private Repository $config) {}

    /** @return array<class-string, array{module: string, local: class-string, remote: class-string}> */
    public function getServices(): array
    {
        /** @var array<class-string, array{module: string, local: class-string, remote: class-string}> */
        return (array) $this->config->get('rpc.services', []);
    }

    public function getHost(string $module): string
    {
        /** @var array<string, string> $hosts */
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
