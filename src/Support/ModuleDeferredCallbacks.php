<?php

declare(strict_types=1);

namespace Distributable\Support;

use Closure;
use Distributable\Services\Modules\ModuleContext;
use Illuminate\Container\Container;
use Illuminate\Support\Defer\DeferredCallback;
use Illuminate\Support\Defer\DeferredCallbackCollection;

/** defer() callbacks run after the response, once the module that deferred them has handed back: each keeps its module. */
final class ModuleDeferredCallbacks extends DeferredCallbackCollection
{
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($value instanceof DeferredCallback) {
            $value->callback = Container::getInstance()->make(ModuleContext::class)->bind(Closure::fromCallable($value->callback));
        }

        parent::offsetSet($offset, $value);
    }
}
