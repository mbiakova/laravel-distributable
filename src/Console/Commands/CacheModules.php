<?php

declare(strict_types=1);

namespace Modulith\Console\Commands;

use Composer\Autoload\ClassLoader;
use Illuminate\Console\Command;
use Microservices\Services\Shadows\ShadowRegistry;
use Modulith\Data\Module;
use Modulith\Services\Modules\DiscoveryCache;
use Modulith\Services\Modules\ModuleRegistry;

/** Run on deploy (php artisan optimize runs it): what the module folders tell is read from a file. */
final class CacheModules extends Command
{
    protected $signature = 'modulith:cache';

    protected $description = 'Cache what the module folders tell: namespaces, databases, copies and shadow sources.';

    public function handle(ModuleRegistry $registry, DiscoveryCache $cache, ShadowRegistry $shadows): int
    {
        // Only the modules this process runs are scanned: the classes of the others must not load here.
        $local = $registry->local();
        $this->autoload($local);
        $names = array_map(static fn (Module $m): string => $m->name, $local);

        $cache->write([
            'modules' => array_map(static fn (Module $module): array => $module->toArray(), $registry->all()),
            'shadows' => array_combine($names, array_map($shadows->scanShadows(...), $names)),
            'sources' => array_combine($names, array_map($shadows->scanSources(...), $names)),
        ]);

        $this->components->info('Modules cached: '.count($registry->all()).'.');

        return self::SUCCESS;
    }

    /**
     * A module added since the last cache is not autoloaded yet; its classes must load to be scanned.
     *
     * @param  list<Module>  $modules
     */
    private function autoload(array $modules): void
    {
        $loader = new ClassLoader;

        foreach ($modules as $module) {
            $loader->addPsr4($module->namespace.'\\', $module->classPath());
        }

        $loader->register();
    }
}
