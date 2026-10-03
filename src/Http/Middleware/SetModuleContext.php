<?php

declare(strict_types=1);

namespace Distributable\Http\Middleware;

use Closure;
use Distributable\Services\Modules\ModuleContext;
use Distributable\Services\Modules\ModuleRegistry;
use Illuminate\Http\Request;
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
