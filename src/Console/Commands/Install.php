<?php

declare(strict_types=1);

namespace Modulith\Console\Commands;

use Illuminate\Console\Command;
use Modulith\Config\Modules;

final class Install extends Command
{
    protected $signature = 'modulith:install';

    protected $description = 'Publish config/modulith.php and create the modules and foundation directories.';

    public function handle(Modules $config): int
    {
        if (! is_file(config_path('modulith.php'))) {
            copy(dirname(__DIR__, 3).'/config/modulith.php', config_path('modulith.php'));
        }

        $modules = $config->getModulesPath();
        $modules = str_starts_with($modules, DIRECTORY_SEPARATOR) ? $modules : base_path($modules);
        is_dir($modules) || mkdir($modules, 0755, true);

        $provider = $config->getFoundationPath().'/FoundationServiceProvider.php';

        if (! is_file($provider)) {
            is_dir(dirname($provider)) || mkdir(dirname($provider), 0755, true);
            file_put_contents($provider, <<<PHP
                <?php

                declare(strict_types=1);

                namespace {$config->getFoundationNamespace()};

                use Modulith\\Providers\\FoundationServiceProvider as BaseServiceProvider;

                final class FoundationServiceProvider extends BaseServiceProvider
                {
                    protected array \$rpc = [];
                }

                PHP);
        }

        $this->components->info('Laravel Modulith is installed. Next: php artisan modulith:make-module <name> [--database]');

        return self::SUCCESS;
    }
}
