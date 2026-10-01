<?php

declare(strict_types=1);

namespace Modulith\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Modulith\Services\Modules\ModuleRegistry;

/** For an image that runs only some modules: config/modulith.php still declares the others. */
final class PurgeModules extends Command
{
    protected $signature = 'modulith:purge {--force : Skip the confirmation}';

    protected $description = 'Delete the folder of every module this process does not run (MODULITH_RUNS).';

    public function handle(ModuleRegistry $registry, Filesystem $files): int
    {
        $remote = array_values(array_filter($registry->all(), static fn ($module): bool => ! $registry->isLocal($module->name)));

        if ($remote === []) {
            $this->components->info('Every module runs here: nothing to purge.');

            return self::SUCCESS;
        }

        $names = implode(', ', array_map(static fn ($module): string => $module->name, $remote));

        if (! $this->option('force') && ! $this->confirm("Delete the folder of [{$names}] from this copy of the application?")) {
            return self::FAILURE;
        }

        foreach ($remote as $module) {
            $files->deleteDirectory($module->path());
            $this->line("→ {$module->name} purged");
        }

        return self::SUCCESS;
    }
}
