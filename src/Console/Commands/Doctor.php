<?php

declare(strict_types=1);

namespace Modulith\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Modulith\Config\Modules;
use Modulith\Config\Rpc;
use Modulith\Services\Modules\ComposerAutoload;
use Modulith\Services\Modules\ModuleRegistry;
use Modulith\Services\Rpc\RpcServices;
use Modulith\Testing\Boundaries;

final class Doctor extends Command
{
    protected $signature = 'modulith:doctor';

    protected $description = 'Check that every module can run: declaration, provider, connections, remote hosts, boundaries.';

    public function handle(ModuleRegistry $registry, Modules $config, Rpc $rpc, RpcServices $services, Boundaries $boundaries, ComposerAutoload $autoload): int
    {
        $problems = [];
        $modulesPath = $config->getModulesPath();
        $root = str_starts_with($modulesPath, DIRECTORY_SEPARATOR) ? $modulesPath : base_path($modulesPath);

        foreach (glob($root.'/*', GLOB_ONLYDIR) ?: [] as $directory) {
            if ($registry->find(Str::snake(basename($directory))) === null) {
                $problems[] = "[{$modulesPath}/".basename($directory).'] is not declared in modulith.modules.';
            }
        }

        foreach ($registry->local() as $module) {
            if (! class_exists($module->provider)) {
                $problems[] = "[{$module->name}] provider {$module->provider} does not exist.";
            }

            foreach ($module->hasDatabase ? [$module->connection(), $module->ownerConnection()] : [] as $connection) {
                if (config("database.connections.{$connection}") === null) {
                    $problems[] = "[{$module->name}] connection [{$connection}] is not declared.";
                }
            }

            foreach ($autoload->stale($module) as $namespace => $paths) {
                $problems[] = "composer.json maps [{$namespace}] to [{$paths['declared']}], but the module lives in [{$paths['expected']}].";
            }
        }

        foreach ($services->all() as $contract => $service) {
            if ($service['module'] === null) {
                $problems[] = "{$contract} is not in the foundation of a declared module.";
            } elseif (! $registry->isLocal($service['module']) && ! $rpc->hasHost($service['module'])) {
                $problems[] = "[{$service['module']}] runs elsewhere and serves {$contract}, but modulith.modules.{$service['module']}.host is not set.";
            }
        }

        if ($services->all() !== [] && $rpc->getSecret() === '') {
            $problems[] = 'Modules serve RPC contracts but modulith.rpc.secret is empty: set MODULITH_RPC_SECRET or APP_KEY.';
        }

        foreach ($boundaries->violations() as $violation) {
            $problems[] = "Boundary crossed: {$violation}";
        }

        foreach ($this->conflictingConfig($registry) as $key => $modules) {
            $problems[] = "Modules [{$modules}] set config [{$key}] to different values: in one process, the module registered last wins.";
        }

        foreach ($problems as $problem) {
            $this->components->error($problem);
        }

        if ($problems === []) {
            $this->components->info('Every module can run.');
        }

        return $problems === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string, string> each config key two local modules set differently, with those modules */
    private function conflictingConfig(ModuleRegistry $registry): array
    {
        $values = [];

        foreach ($registry->local() as $module) {
            foreach (glob($module->path().'/config/*.php') ?: [] as $file) {
                foreach ($this->scalars(require $file, pathinfo($file, PATHINFO_FILENAME)) as $key => $value) {
                    $values[$key][$module->name] = $value;
                }
            }
        }

        $conflicts = [];

        foreach ($values as $key => $byModule) {
            if (count(array_unique(array_map(serialize(...), $byModule))) > 1) {
                $conflicts[$key] = implode(', ', array_keys($byModule));
            }
        }

        return $conflicts;
    }

    /**
     * Lists are left out: the modules' items add up instead of replacing each other.
     *
     * @return array<string, mixed>
     */
    private function scalars(mixed $value, string $key): array
    {
        if (! is_array($value)) {
            return [$key => $value];
        }

        if (array_is_list($value)) {
            return [];
        }

        $scalars = [];

        foreach ($value as $child => $childValue) {
            $scalars = [...$scalars, ...$this->scalars($childValue, "{$key}.{$child}")];
        }

        return $scalars;
    }
}
