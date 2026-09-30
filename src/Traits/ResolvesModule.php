<?php

declare(strict_types=1);

namespace Modulith\Traits;

use Modulith\Data\Module;
use Modulith\Exceptions\ModuleException;
use Modulith\Services\Modules\ModuleRegistry;

trait ResolvesModule
{
    /**
     * The module this class belongs to, inferred from its namespace.
     *
     * @throws ModuleException for classes outside any module — there is no fallback module.
     */
    public Module $module {
        get => app(ModuleRegistry::class)->forClass(static::class)
            ?? throw ModuleException::outsideModule(static::class);
    }
}
