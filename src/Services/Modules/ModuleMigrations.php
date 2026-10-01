<?php

declare(strict_types=1);

namespace Modulith\Services\Modules;

use Modulith\Config\Modules;
use Modulith\Data\Module;
use Modulith\Services\Shadows\ShadowRegistry;

/**
 * Which migrations run in which database: the application's own in the default one, and in each
 * module database the package's tables, the application's, the module's and its copies'.
 */
final readonly class ModuleMigrations
{
    public function __construct(
        private ModuleRegistry $registry,
        private ShadowRegistry $shadows,
        private Modules $config,
    ) {}

    /** @return list<string> the application's migrations, plus those of the modules that have no database of their own */
    public function forApplication(): array
    {
        $paths = [database_path('migrations')];

        foreach ($this->registry->local() as $module) {
            if (! $module->hasDatabase && is_dir($module->path().'/database/migrations')) {
                $paths[] = $module->path().'/database/migrations';
            }
        }

        return $paths;
    }

    /** @return list<string> */
    public function forModule(Module $module): array
    {
        $paths = [dirname(__DIR__, 3).'/database/migrations', database_path('migrations')];

        if (is_dir($module->path().'/database/migrations')) {
            $paths[] = $module->path().'/database/migrations';
        }

        return [...$paths, ...$this->shadowMigrations($module)];
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
