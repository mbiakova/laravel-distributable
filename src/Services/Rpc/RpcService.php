<?php

declare(strict_types=1);

namespace Modulith\Services\Rpc;

use Closure;
use Illuminate\Cache\TaggableStore;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modulith\Config\Modules;
use Modulith\Config\Rpc;
use Modulith\Contracts\Rpc\RpcTransport;
use Modulith\Data\Module;
use Modulith\Exceptions\ModuleException;
use Modulith\Services\Modules\ModuleRegistry;
use Throwable;

/**
 * Base of the implementation bound when a module runs in another process. It lives in that
 * module's foundation (Foundation\{Module}\Services), so every caller has it, split or not.
 */
abstract class RpcService
{
    /** Long by design: the owner forgets a key when it writes; expiry only catches a missed forget. */
    protected const int DEFAULT_TTL = 604800;

    /** The module this service calls, read from its foundation namespace. */
    public Module $module {
        get {
            $root = app(Modules::class)->getFoundationNamespace();

            return app(ModuleRegistry::class)->forFoundationClass(static::class, $root)
                ?? throw ModuleException::outsideFoundation(static::class, $root);
        }
    }

    public function __construct(protected readonly RpcTransport $transport) {}

    /**
     * Calls $method of the contract this service is mapped to, on the module that implements it:
     * in this process when it runs here, over its transport otherwise.
     *
     * @param  array<string, mixed>  $arguments  by name, as the contract's method declares them
     */
    protected function call(string $method, array $arguments = []): mixed
    {
        $contract = app(RpcServices::class)->contractOf(static::class)
            ?? throw ModuleException::unmappedRpcService(static::class);

        return $this->transport->invoke($this->module, $contract, $method, $arguments);
    }

    /**
     * Returns $key from the cache; on a miss, $fetch the raw answer and keep it for $ttl. The cache
     * holds the raw answer and $map runs on every read, so a changed shape never outlives a deploy.
     *
     * @template T
     *
     * @param  Closure(): mixed  $fetch
     * @param  Closure(array<string, mixed>): T  $map
     * @param  list<string>|null  $tags
     * @return T|null
     */
    protected function readThrough(string $key, int $ttl, Closure $fetch, Closure $map, ?array $tags = null): mixed
    {
        $store = $this->cache($tags);
        $raw = $store->get($key);

        if (! is_array($raw)) {
            $raw = $fetch();

            if (! is_array($raw)) {
                return null;
            }

            $store->put($key, $raw, $ttl);
        }

        return $this->mapOrForget($key, $raw, $map, $tags);
    }

    /**
     * A read-through whose lifetime comes from the mapped answer (a token kept until it expires);
     * a lifetime of zero or less is returned as absent and not kept.
     *
     * @template T
     *
     * @param  Closure(): mixed  $fetch
     * @param  Closure(array<string, mixed>): T  $map
     * @param  Closure(T): int  $ttlOf  seconds
     * @return T|null
     */
    protected function readThroughUntil(string $key, Closure $fetch, Closure $map, Closure $ttlOf): mixed
    {
        $raw = $this->cache()->get($key);

        if (is_array($raw)) {
            return $this->mapOrForget($key, $raw, $map);
        }

        $raw = $fetch();
        $mapped = is_array($raw) ? $this->mapOrForget($key, $raw, $map) : null;

        if ($mapped === null || ($ttl = $ttlOf($mapped)) <= 0) {
            return null;
        }

        $this->cache()->put($key, $raw, $ttl);

        return $mapped;
    }

    /** @param list<string>|null $tags the ones the entry was written under */
    protected function forget(string $key, ?array $tags = null): void
    {
        $this->cache($tags)->forget($key);
    }

    /**
     * The store shared by every process (modulith.rpc.cache): the caller keeps the answer, the
     * owner forgets it, so both must reach the same one.
     *
     * @param  list<string>|null  $tags
     */
    protected function cache(?array $tags = null): Repository
    {
        $store = Cache::store(app(Rpc::class)->getCacheStore());

        return $tags !== null && $store->getStore() instanceof TaggableStore ? $store->tags($tags) : $store;
    }

    /**
     * An answer $map cannot read anymore is dropped from the cache and read as absent.
     *
     * @param  array<string, mixed>  $raw
     * @param  list<string>|null  $tags
     */
    private function mapOrForget(string $key, array $raw, Closure $map, ?array $tags = null): mixed
    {
        try {
            return $map($raw);
        } catch (Throwable $e) {
            Log::warning('Discarded an RPC answer that no longer maps', ['key' => $key, 'raw' => $raw, 'error' => $e->getMessage()]);
            $this->forget($key, $tags);

            return null;
        }
    }
}
