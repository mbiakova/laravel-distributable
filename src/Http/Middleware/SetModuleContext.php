<?php

declare(strict_types=1);

namespace Modulith\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modulith\Services\Modules\ModuleContext;
use Modulith\Services\Modules\ModuleRegistry;
use Symfony\Component\HttpFoundation\Response;

/** Put on every route a module loads: the request runs in that module's context. */
final readonly class SetModuleContext
{
    public function __construct(
        private ModuleRegistry $registry,
        private ModuleContext $context,
    ) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        $this->context->switchTo($this->registry->get($module));

        return $next($request);
    }
}
