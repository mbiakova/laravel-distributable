<?php

declare(strict_types=1);

namespace Distributable\Http\Controllers;

use Distributable\Data\Module;
use Distributable\Services\Modules\ModuleRegistry;
use Illuminate\Http\JsonResponse;

/** Answers which modules this process runs — the first thing to check on a split deployment. */
final class StatusController
{
    public function __invoke(ModuleRegistry $registry): JsonResponse
    {
        return new JsonResponse([
            'message' => 'Hello from laravel-distributable',
            'modules' => array_map(static fn (Module $module): string => $module->name, $registry->local()),
            'status' => 'ok',
        ]);
    }
}
