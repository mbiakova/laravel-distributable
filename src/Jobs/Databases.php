<?php

declare(strict_types=1);

namespace Modulith\Jobs;

use Modulith\Services\Modules\ModuleRegistry;

final class Databases
{
    /** @return list<string> the connections of the local modules that have a database */
    public static function of(ModuleRegistry $registry): array
    {
        $connections = [];

        foreach ($registry->local() as $module) {
            if ($module->hasDatabase) {
                $connections[] = $module->connection();
            }
        }

        return $connections;
    }
}
