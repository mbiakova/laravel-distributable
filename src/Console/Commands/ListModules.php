<?php

declare(strict_types=1);

namespace Modulith\Console\Commands;

use Illuminate\Console\Command;
use Modulith\Config\Rpc;
use Modulith\Data\Module;
use Modulith\Services\Modules\ModuleRegistry;

final class ListModules extends Command
{
    protected $signature = 'modulith:list';

    protected $description = 'List the modules, and where each one runs.';

    public function handle(ModuleRegistry $registry, Rpc $rpc): int
    {
        $this->table(
            ['Module', 'Namespace', 'Runs here', 'Database', 'Remote host'],
            array_map(static fn (Module $module): array => [
                $module->name,
                $module->namespace,
                $registry->isLocal($module->name) ? 'yes' : 'no',
                $module->hasDatabase ? $module->connection() : '—',
                ! $registry->isLocal($module->name) && $rpc->hasHost($module->name) ? $rpc->getHost($module->name) : '—',
            ], $registry->all()),
        );

        return self::SUCCESS;
    }
}
