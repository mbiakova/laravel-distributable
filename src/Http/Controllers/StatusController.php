<?php

declare(strict_types=1);

namespace Modulith\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modulith\Data\Module;
use Modulith\Services\Modules\ModuleRegistry;

/** Answers which modules this process runs — the first thing to check on a split deployment. */
final class StatusController
{
    public function __invoke(ModuleRegistry $registry): JsonResponse
    {
        return new JsonResponse([
            'message' => 'Hello from laravel-modulith',
            'modules' => array_map(static fn (Module $module): string => $module->name, $registry->local()),
            'status' => 'ok',
        ]);
    }
}
