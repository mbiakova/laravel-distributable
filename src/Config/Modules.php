<?php

declare(strict_types=1);

namespace Modulith\Config;

use Illuminate\Contracts\Config\Repository;
use Modulith\Data\Module;

/** The modulith.* settings, typed and defaulted; read live, never snapshotted. */
final readonly class Modules
{
    public function __construct(private Repository $config) {}

    /** @return list<string> the names modulith.modules declares */
    public function getDeclaredModules(): array
    {
        return array_map(strval(...), array_keys((array) $this->config->get('modulith.modules', [])));
    }

    /** The URL a module answers on when it runs in another process; null when none is set. */
    public function getHost(string $module): ?string
    {
        $host = $this->config->get("modulith.modules.{$module}.host");

        return is_array($host) ? (is_string($host['url'] ?? null) ? $host['url'] : null) : (is_string($host) ? $host : null);
    }

    /** @return list<string> ['*'] for every module, or the names MODULITH_RUNS lists */
    public function getLoadedModules(): array
    {
        $names = array_map(trim(...), explode(',', (string) $this->config->get('modulith.runs', '*')));

        return array_values(array_filter($names, static fn (string $name): bool => $name !== ''));
    }

    public function getModulesPath(): string
    {
        return (string) $this->config->get('modulith.paths.modules', 'apps');
    }

    public function getModulesNamespace(): string
    {
        return (string) $this->config->get('modulith.namespaces.modules', 'Apps');
    }

    /** Absolute; the foundation directory, or the one of a module (foundation/Iam) when given. */
    public function getFoundationPath(?Module $module = null): string
    {
        $path = (string) $this->config->get('modulith.paths.foundation', 'foundation');
        $root = str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);

        return $module === null ? $root : $root.'/'.basename($module->path());
    }

    public function getFoundationNamespace(): string
    {
        return (string) $this->config->get('modulith.namespaces.foundation', 'Foundation');
    }

    public function getStatusRoute(): ?string
    {
        $route = $this->config->get('modulith.status_route');

        return is_string($route) && $route !== '' ? $route : null;
    }
}
