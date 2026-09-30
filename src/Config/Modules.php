<?php

declare(strict_types=1);

namespace Modulith\Config;

use Illuminate\Contracts\Config\Repository;
use Modulith\Services\Modules\ManifestSource;

/** The modulith.* settings, typed and defaulted; read live, never snapshotted. */
final readonly class Modules
{
    public function __construct(private Repository $config) {}

    /** @return class-string */
    public function getSource(): string
    {
        /** @var class-string */
        return (string) $this->config->get('modulith.source', ManifestSource::class);
    }

    /** @return list<string> ['*'] for every module, or the names WITH_MODULES lists */
    public function getLoadedModules(): array
    {
        $names = array_map(trim(...), explode(',', (string) $this->config->get('modulith.with', '*')));

        return array_values(array_filter($names, static fn (string $name): bool => $name !== ''));
    }

    public function getModulesPath(): string
    {
        return (string) $this->config->get('modulith.modules_path', 'apps');
    }

    public function getModulesNamespace(): string
    {
        return (string) $this->config->get('modulith.modules_namespace', 'Apps');
    }

    /** Absolute, resolved against the project root when relative. */
    public function getFoundationPath(): string
    {
        $path = (string) $this->config->get('modulith.foundation_path', 'foundation');

        return str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
    }

    public function getFoundationNamespace(): string
    {
        return (string) $this->config->get('modulith.foundation_namespace', 'Foundation');
    }

    public function getStatusRoute(): ?string
    {
        $route = $this->config->get('modulith.status_route');

        return is_string($route) && $route !== '' ? $route : null;
    }
}
