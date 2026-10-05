<?php

declare(strict_types=1);

namespace Distributable\Console\Commands;

use Distributable\Config\Modules;
use Distributable\Data\Module;
use Distributable\Services\Modules\ComposerAutoload;
use Illuminate\Console\Command;

final class MakeModule extends Command
{
    protected $signature = 'distributable:make-module
        {name : The module name, snake_case (e.g. point_of_sale)}
        {--database : Give the module its own database connections}';

    protected $description = 'Create a module and its foundation directory.';

    public function handle(Modules $config, ComposerAutoload $autoload): int
    {
        $module = Module::fromName((string) $this->argument('name'), $config->getModulesNamespace(), $config->getModulesPath());

        if (is_dir($module->path())) {
            $this->components->error("Module [{$module->name}] already exists at {$module->path()}.");

            return self::FAILURE;
        }

        $providerClass = class_basename($module->provider);

        $files = [
            "app/Providers/{$providerClass}.php" => <<<PHP
                <?php

                declare(strict_types=1);

                namespace {$module->namespace}\\Providers;

                use Distributable\\Providers\\ServiceProvider;

                final class {$providerClass} extends ServiceProvider {}

                PHP,
            'routes/api.php' => "<?php\n\ndeclare(strict_types=1);\n\nuse Illuminate\\Support\\Facades\\Route;\n",
        ];

        if ($this->option('database')) {
            $files['config/database.php'] = <<<PHP
                <?php

                declare(strict_types=1);

                return ['connections' => [
                    '{$module->name}' => ['driver' => env('DB_CONNECTION', 'pgsql'), 'database' => '{$module->name}', 'username' => '{$module->name}_app'],
                    '{$module->name}_owner' => ['driver' => env('DB_CONNECTION', 'pgsql'), 'database' => '{$module->name}', 'username' => '{$module->name}_owner'],
                ]];

                PHP;
        }

        foreach ($files as $path => $contents) {
            $this->write($module->path().'/'.$path, $contents);
        }

        $foundation = $config->getFoundationPath($module).'/Contracts';
        is_dir($foundation) || mkdir($foundation, 0755, true);

        $this->components->info("Module [{$module->name}] created at {$module->path()}.");
        $this->declare($module);

        if ($autoload->add([...$autoload->foundationEntries(), ...$autoload->entriesOf($module)], $autoload->devEntriesOf($module))) {
            $this->components->info('Added to composer.json, for your IDE: run composer dump-autoload.');
        }

        return self::SUCCESS;
    }

    private function declare(Module $module): void
    {
        $config = config_path('distributable.php');
        $contents = is_file($config) ? (string) file_get_contents($config) : '';
        $declared = preg_replace("/('modules'\s*=>\s*\[)/", "$1\n        '{$module->name}' => [],", $contents, 1, $count);

        if ($count === 1 && is_string($declared)) {
            file_put_contents($config, $declared);
            $this->components->info('Declared in config/distributable.php.');

            return;
        }

        $this->components->warn("Declare it in config/distributable.php: 'modules' => ['{$module->name}' => []].");
    }

    private function write(string $path, string $contents): void
    {
        is_dir(dirname($path)) || mkdir(dirname($path), 0755, true);
        file_put_contents($path, $contents);
    }
}
