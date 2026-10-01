<?php

declare(strict_types=1);

namespace Modulith\Config;

use Illuminate\Contracts\Config\Repository;
use Modulith\Data\Module;
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

    /** Absolute; the foundation directory, or the one of a module (foundation/Iam) when given. */
    public function getFoundationPath(?Module $module = null): string
    {
        $path = (string) $this->config->get('modulith.foundation_path', 'foundation');
        $root = str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);

        return $module === null ? $root : $root.'/'.basename($module->path());
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
