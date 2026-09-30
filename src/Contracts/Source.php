<?php

declare(strict_types=1);

namespace Modulith\Contracts;

use Modulith\Data\Module;

/** Supplies the application's module list to the registry, whatever the declaration mechanism. */
interface Source
{
    /** @return list<Module> */
    public function modules(): array;
}
