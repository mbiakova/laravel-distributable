<?php

declare(strict_types=1);

namespace Modulith\Console\Migrations;

use Modulith\Migrations\ShadowMigration;
use Modulith\Services\Modules\ModuleContext;
use Modulith\Services\Modules\ModuleMigrations;
use Modulith\Services\Modules\ModuleRegistry;
use Symfony\Component\Console\Input\InputOption;

/**
 * Turns a Laravel migrate:* command into one run per database: the application's default one,
 * then each local module's, on its {module}_owner connection. An explicit --database or --path
 * is Laravel's own command, unchanged — which is also how each run executes.
 */
trait RunsForEachModule
{
    protected function addModuleOption(): void
    {
        $this->addOption('module', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only the databases of these modules');
    }

    public function handle(): int
    {
        if ($this->input->getOption('database') !== null || $this->input->getOption('path') !== []) {
            return (int) parent::handle();
        }

        $migrations = $this->laravel->make(ModuleMigrations::class);
        $only = (array) $this->input->getOption('module');
        $exitCode = self::SUCCESS;

        if ($only === []) {
            $exitCode = $this->runOn((string) $this->laravel['config']->get('database.default'), $migrations->forApplication());
        }

        foreach ($this->laravel->make(ModuleRegistry::class)->local() as $module) {
            if (! $module->hasDatabase || ($only !== [] && ! in_array($module->name, $only, true)) || $exitCode !== self::SUCCESS) {
                continue;
            }

            $this->components->info("Module [{$module->name}]");
            ShadowMigration::$keeper = $module->name;

            // In the module's context, so --seed runs the module's own seeders and not the application's again.
            $exitCode = $this->laravel->make(ModuleContext::class)->within(
                $module,
                fn (): int => $this->runOn($module->ownerConnection(), $migrations->forModule($module)),
            );
        }

        return $exitCode;
    }

    /** @param list<string> $paths */
    private function runOn(string $connection, array $paths): int
    {
        $this->input->setOption('database', $connection);
        $this->input->setOption('path', $paths);
        $this->input->setOption('realpath', true);

        try {
            return (int) parent::handle();
        } finally {
            $this->input->setOption('database', null);
            $this->input->setOption('path', []);
        }
    }
}
