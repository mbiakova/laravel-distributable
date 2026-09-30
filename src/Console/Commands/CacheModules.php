<?php

declare(strict_types=1);

namespace Modulith\Console\Commands;

use Composer\Autoload\ClassLoader;
use Illuminate\Console\Command;
use Modulith\Config\Modules;
use Modulith\Contracts\Modules\Source;
use Modulith\Data\Module;
use Modulith\Exceptions\ConfigurationException;
use Modulith\Services\Modules\DiscoveryCache;
use Modulith\Services\Shadows\ShadowRegistry;

/** Run on deploy (php artisan optimize runs it): discovery is read from a file, not the tree. */
final class CacheModules extends Command
{
    protected $signature = 'modulith:cache';

    protected $description = 'Cache the modules, their copies and their shadow sources.';

    public function handle(Modules $config, DiscoveryCache $cache, ShadowRegistry $shadows): int
    {
        $source = $this->laravel->make($config->getSource());

        if (! $source instanceof Source) {
            throw ConfigurationException::invalidSource($source::class, Source::class);
        }

        $modules = $source->modules();
        $this->autoload($modules);

        $cache->write([
            'modules' => array_map(static fn (Module $module): array => $module->toArray(), $modules),
            'shadows' => array_combine(array_map(static fn (Module $m): string => $m->name, $modules), array_map($shadows->scanShadows(...), $modules)),
            'sources' => array_combine(array_map(static fn (Module $m): string => $m->name, $modules), array_map($shadows->scanSources(...), $modules)),
        ]);

        $this->components->info('Modules cached: '.count($modules).'.');

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
