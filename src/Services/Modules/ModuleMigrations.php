<?php

declare(strict_types=1);

namespace Distributable\Services\Modules;

use Distributable\Config\Modules;
use Distributable\Data\Module;
use Illuminate\Container\Container;
use Microservices\Services\Shadows\ShadowRegistry;

/**
 * The migrations of a module database: the application's (laravel-microservices' outbox tables
 * among them), the module's and its copies'.
 */
final readonly class ModuleMigrations
{
    public function __construct(
        private ModuleRegistry $registry,
        private ShadowRegistry $shadows,
        private Modules $config,
    ) {}

    /** @return list<string> */
    public function forModule(Module $module): array
    {
        $paths = $this->applicationPaths();

        if (is_dir($module->path().'/database/migrations')) {
            $paths[] = $module->path().'/database/migrations';
        }

        return [...$paths, ...$this->shadowMigrations($module)];
    }

    /**
     * Laravel skips the loadMigrationsFrom() paths once a --path is given, and every run here gives one.
     *
     * @return list<string>
     */
    private function applicationPaths(): array
    {
        /** @var list<string> $packages */
        $packages = Container::getInstance()->make('migrator')->paths();

        return array_values(array_unique([database_path('migrations'), ...$packages]));
    }

    /**
     * The migrations of the copies this module keeps, published by their owners in foundation/{Owner}/database/shadows/.
     *
     * @return list<string>
     */
    private function shadowMigrations(Module $module): array
    {
        $files = [];

        foreach ($this->shadows->localShadows() as $shadow) {
            if ($this->registry->forClass($shadow)?->name !== $module->name) {
                continue;
            }

            $owner = $this->registry->get($shadow::owner());
            $files = [...$files, ...(glob($this->config->getFoundationPath($owner).'/database/shadows/*_'.$shadow::sourceTable().'_shadow*.php') ?: [])];
        }

        sort($files);

        return $files;
    }
}
