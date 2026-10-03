<?php

declare(strict_types=1);

namespace Distributable\Console\Commands;

use Distributable\Config\Modules;
use Distributable\Data\Module;
use Distributable\Services\Modules\ModuleRegistry;
use Illuminate\Console\Command;

final class ListModules extends Command
{
    protected $signature = 'distributable:list';

    protected $description = 'List the modules, and where each one runs.';

    public function handle(ModuleRegistry $registry, Modules $config): int
    {
        $this->table(
            ['Module', 'Namespace', 'Runs here', 'Database', 'Remote host'],
            array_map(static fn (Module $module): array => [
                $module->name,
                $module->namespace,
                $registry->isLocal($module->name) ? 'yes' : 'no',
                $module->hasDatabase ? $module->connection() : '—',
                ! $registry->isLocal($module->name) ? ($config->getHost($module->name) ?? '—') : '—',
            ], $registry->all()),
        );

        return self::SUCCESS;
    }
}
