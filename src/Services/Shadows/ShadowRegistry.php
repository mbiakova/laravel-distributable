<?php

declare(strict_types=1);

namespace Modulith\Services\Shadows;

use Illuminate\Database\Eloquent\Model;
use Modulith\Contracts\Shadows\Shadowed;
use Modulith\Data\Module;
use Modulith\Models\ShadowModel;
use Modulith\Services\Modules\DiscoveryCache;
use Modulith\Services\Modules\ModuleRegistry;
use ReflectionClass;

/** Finds, among the modules this process runs, the copies of a source table and its source model. */
final class ShadowRegistry
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly DiscoveryCache $cache,
    ) {}

    /** @return list<class-string<ShadowModel>> the concrete copies of $sourceTable kept here */
    public function shadowsOf(string $sourceTable): array
    {
        return array_values(array_filter(
            $this->localShadows(),
            static fn (string $shadow): bool => $shadow::sourceTable() === $sourceTable,
        ));
    }

    /** @return list<class-string<ShadowModel>> every concrete copy the local modules keep */
    public function localShadows(): array
    {
        $shadows = [];

        foreach ($this->registry->local() as $module) {
            /** @var list<class-string<ShadowModel>> $kept */
            $kept = $this->cache->load()['shadows'][$module->name] ?? $this->scanShadows($module);
            $shadows = [...$shadows, ...$kept];
        }

        return $shadows;
    }

    /** The local source model of $sourceTable, if this process runs its owner. */
    public function sourceOf(string $sourceTable): ?Shadowed
    {
        foreach ($this->registry->local() as $module) {
            foreach ($this->cache->load()['sources'][$module->name] ?? $this->scanSources($module) as $class) {
                /** @var Model&Shadowed $model */
                $model = new $class;

                if ($model->getTable() === $sourceTable) {
                    return $model;
                }
            }
        }

        return null;
    }

    /** @return list<class-string<ShadowModel>> */
    public function scanShadows(Module $module): array
    {
        $shadows = [];

        foreach (modulith_classes_with(ShadowModel::class, $module->classPath()) as $class) {
            if (! (new ReflectionClass($class))->isAbstract()) {
                /** @var class-string<ShadowModel> $class */
                $shadows[] = $class;
            }
        }

        return $shadows;
    }

    /** @return list<class-string> */
    public function scanSources(Module $module): array
    {
        return modulith_classes_with(Shadowed::class, $module->classPath());
    }
}
