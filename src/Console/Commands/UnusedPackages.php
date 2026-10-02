<?php

declare(strict_types=1);

namespace Modulith\Console\Commands;

use Illuminate\Console\Command;
use Modulith\Services\Modules\ModuleRegistry;

/** Run before modulith:purge, which deletes the files this reads: the output goes to `composer update`, which removes those packages only. */
final class UnusedPackages extends Command
{
    protected $signature = 'modulith:unused-packages';

    protected $description = 'List the Composer packages only the modules this process does not run (MODULITH_RUNS) require.';

    public function handle(ModuleRegistry $registry): int
    {
        $kept = $this->requiredBy(base_path('composer.json'));
        $dropped = [];

        foreach ($registry->all() as $module) {
            $packages = $this->requiredBy($module->path().'/composer.json');

            $registry->isLocal($module->name) ? array_push($kept, ...$packages) : array_push($dropped, ...$packages);
        }

        $this->line(implode(' ', array_values(array_unique(array_diff($dropped, $kept)))));

        return self::SUCCESS;
    }

    /** @return list<string> the packages the file requires, without the platform ones (php, ext-*) */
    private function requiredBy(string $file): array
    {
        $composer = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];
        $require = is_array($composer) && is_array($composer['require'] ?? null) ? $composer['require'] : [];

        return array_values(array_filter(array_map(strval(...), array_keys($require)), static fn (string $package): bool => str_contains($package, '/')));
    }
}
