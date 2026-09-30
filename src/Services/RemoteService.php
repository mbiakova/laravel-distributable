<?php

declare(strict_types=1);

namespace Modulith\Services;

use Closure;
use Illuminate\Support\Facades\Cache;
use Modulith\Contracts\RpcTransport;
use Modulith\Traits\ResolvesModule;

/**
 * Base of a module's remote implementation: the one bound when that module runs in another
 * process. It lives in the module it calls, which is how it knows where its calls go.
 */
abstract class RemoteService
{
    use ResolvesModule;

    public function __construct(protected readonly RpcTransport $transport) {}

    /** @param array<string, mixed> $payload */
    protected function call(string $resource, string $operation, array $payload = []): mixed
    {
        return $this->transport->invoke($this->module, $resource, $operation, $payload);
    }

    /**
     * Read-through cache for an answer that changes rarely; the owning module forgets the key
     * when it writes.
     *
     * @template T
     *
     * @param  Closure(): T  $fetch
     * @return T
     */
    protected function remember(string $key, int $ttl, Closure $fetch): mixed
    {
        return Cache::remember($key, $ttl, $fetch);
    }
}
