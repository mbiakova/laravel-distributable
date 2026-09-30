<?php

declare(strict_types=1);

namespace Modulith\Services\Rpc;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modulith\Config\Modules;
use Modulith\Contracts\Rpc\RpcTransport;
use Modulith\Data\Module;
use Modulith\Exceptions\ModuleException;
use Modulith\Services\Modules\ModuleRegistry;

/**
 * Base of the implementation bound when a module runs in another process. It lives in that
 * module's foundation (Foundation\{Module}\Services), so every caller has it, split or not.
 */
abstract class RpcService
{
    /** The module this service calls, read from its foundation namespace. */
    public Module $module {
        get {
            $root = app(Modules::class)->getFoundationNamespace().'\\';

            if (! str_starts_with(static::class, $root)) {
                throw ModuleException::outsideFoundation(static::class, $root);
            }

            return app(ModuleRegistry::class)->get(Str::snake(Str::before(Str::after(static::class, $root), '\\')));
        }
    }

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

    /**
     * Read-through cache whose lifetime comes from the answer itself (a token cached until it
     * expires); a null answer or a lifetime of zero is returned without being cached.
     *
     * @template T
     *
     * @param  Closure(): (T|null)  $fetch
     * @param  Closure(T): int  $ttlOf  seconds
     * @return T|null
     */
    protected function rememberUntil(string $key, Closure $fetch, Closure $ttlOf): mixed
    {
        if (Cache::has($key)) {
            return Cache::get($key);
        }

        $value = $fetch();

        if ($value !== null && ($ttl = $ttlOf($value)) > 0) {
            Cache::put($key, $value, $ttl);
        }

        return $value;
    }

    protected function forget(string $key): void
    {
        Cache::forget($key);
    }
}
