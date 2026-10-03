<?php

declare(strict_types=1);

namespace Distributable\Console\Commands;

use Distributable\Config\Modules;
use Distributable\Data\Module;
use Distributable\Services\Modules\ComposerAutoload;
use Distributable\Services\Modules\ModuleRegistry;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

final class DeleteModule extends Command
{
    protected $signature = 'distributable:delete-module
        {name : The module to delete}
        {--force : Do not ask for confirmation}';

    protected $description = 'Delete a module: its folder, its foundation folder, its declaration and its composer.json entries. Its database is left as it is.';

    public function handle(ModuleRegistry $registry, Modules $config, ComposerAutoload $autoload, Filesystem $files): int
    {
        $module = $registry->get((string) $this->argument('name'));
        $foundation = $config->getFoundationPath($module);

        if (! $this->option('force') && ! $this->confirm("Delete [{$module->path()}] and [{$foundation}]? The database of [{$module->name}] is left as it is.")) {
            return self::FAILURE;
        }

        $entries = array_keys([...$autoload->entriesOf($module), ...$autoload->devEntriesOf($module)]);

        $files->deleteDirectory($module->path());
        $files->deleteDirectory($foundation);
        $autoload->remove($entries);
        $this->undeclare($module);

        $this->components->info("Module [{$module->name}] deleted. Run distributable:doctor: other modules may still use what it shared.");

        return self::SUCCESS;
    }

    private function undeclare(Module $module): void
    {
        $config = config_path('distributable.php');
        $contents = is_file($config) ? (string) file_get_contents($config) : '';
        $undeclared = preg_replace("/^[ \t]*'".preg_quote($module->name, '/')."'\s*=>\s*\[[^\]\n]*\],?[ \t]*\n/m", '', $contents, 1, $count);

        if ($count === 1 && is_string($undeclared)) {
            file_put_contents($config, $undeclared);

            return;
        }

        $this->components->warn("Remove it from config/distributable.php: 'modules' => ['{$module->name}' => …].");
    }
}
