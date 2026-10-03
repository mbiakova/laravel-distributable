<?php

declare(strict_types=1);

namespace Distributable\Console\Commands;

use Distributable\Config\Modules;
use Distributable\Services\Modules\ComposerAutoload;
use Illuminate\Console\Command;

final class Install extends Command
{
    protected $signature = 'distributable:install';

    protected $description = 'Publish config/distributable.php and create the modules and foundation directories.';

    public function handle(Modules $config, ComposerAutoload $autoload): int
    {
        if (! is_file(config_path('distributable.php'))) {
            copy(dirname(__DIR__, 3).'/config/distributable.php', config_path('distributable.php'));
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

                use Distributable\\Providers\\FoundationServiceProvider as BaseServiceProvider;

                final class FoundationServiceProvider extends BaseServiceProvider
                {
                    protected array \$rpc = [];
                }

                PHP);
        }

        $autoload->add($autoload->foundationEntries());

        $this->components->info('Laravel Distributable is installed. Next: php artisan distributable:make-module <name> [--database]');

        return self::SUCCESS;
    }
}
