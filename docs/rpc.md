# Calls between modules (RPC)

RPC is for reading data another module owns, when you need the answer right away. The module that
owns the data puts the contract and an `RpcService` in its foundation, so every caller has them
whether the module runs in the same process or not. It implements the contract in its own
directory:

```
foundation/Iam/Contracts/IamService.php      the contract
foundation/Iam/Services/IamRpcService.php    extends RpcService: every call to iam goes through it
foundation/FoundationServiceProvider.php     binds IamService to IamRpcService
apps/Iam/app/Services/IamService.php         implements the contract with iam's own data
apps/Iam/app/Providers/IamServiceProvider    declares that implementation in $services
```

## One path, wherever the module runs

A caller always gets the `RpcService`. Its `call($method, $arguments)` names a method of the
contract, and the package picks the transport from where the module runs:

```
analytics ─► IamService (the contract) ─► IamRpcService::call('findUser', ['id' => 1])
                                                    │
                              RpcTransportManager: does iam run in this process?
           ┌────────────────────────────────────────┴───────────────────────────────────────┐
           │ yes: LocalRpcTransport                                                         │ no: the transport of iam's host (http, or yours)
           ▼                                                                                ▼
   iam's $services: Apps\Iam\Services\IamService                         POST {host}/iam/rpc/findUser {contract, arguments}
   called in iam's context, so its queries                                         │ signed, checked by the `rpc` group
   land in iam's database                                                          ▼
                                                                   the same call, in iam's context, on the other side
```

Both sides end in the same place, `Modulith\Services\Rpc\LocalServices`. It looks up the
implementation the module declared in `$services`, runs the method in the module's context, then
returns the answer as JSON decodes it. A caller therefore gets the same thing whether iam runs
next to it or in another process, and the implementation never has to switch to its own database:
the package already did. Only a method declared on the contract can be called.

```php
// foundation/FoundationServiceProvider.php
final class FoundationServiceProvider extends \Modulith\Providers\FoundationServiceProvider
{
    protected array $rpc = [
        IamService::class => IamRpcService::class,
    ];
}

// apps/Iam/app/Providers/IamServiceProvider.php
final class IamServiceProvider extends \Modulith\Providers\ModuleServiceProvider
{
    protected array $services = [
        \Foundation\Iam\Contracts\IamService::class => \Apps\Iam\Services\IamService::class,
    ];
}
```

```php
// foundation/Iam/Services/IamRpcService.php
final class IamRpcService extends \Modulith\Services\Rpc\RpcService implements IamService
{
    public function findUser(int $id): ?array
    {
        return $this->readThrough(
            "iam:user:{$id}",
            self::DEFAULT_TTL,                                        // a week: iam forgets the key when the user changes
            fn () => $this->call('findUser', ['id' => $id]),          // the raw answer, the one kept in the cache
            fn (array $raw) => ['id' => $raw['id'], 'name' => $raw['name']],
        );
    }
}

// apps/Iam/app/Services/IamService.php: a plain query, already in iam's context
final class IamService implements \Foundation\Iam\Contracts\IamService
{
    public function findUser(int $id): ?array
    {
        $user = User::query()->find($id);

        return $user === null ? null : ['id' => $user->id, 'name' => $user->name];
    }
}
```

Other modules type-hint `Foundation\Iam\Contracts\IamService` and don't need to know where iam
runs. The module writes no route: the package serves `POST {module}/rpc/{method}` for every module
the process runs. A call to another process is signed with an HMAC of the timestamp, a nonce, the
path, the body and the propagated context, and the `rpc` middleware group rejects a request that
is unsigned, too old, modified or replayed (each nonce is accepted once, using the cache). A
`null` answer travels as a 404 and comes back as `null`. A contract with no `RpcService` stays
bound to the module's implementation, and works only in a process that runs that module.

`RpcService` caches answers with a read-through:

| Method | What it does |
|---|---|
| `readThrough($key, $ttl, $fetch, $map, $tags)` | reads the cache; on a miss, calls `$fetch` and keeps its raw answer. `$map` turns the raw answer into what you return, on every read, so a mapping changed by a deploy applies to answers cached before it. An answer `$map` can no longer read is logged, dropped and returned as `null`. |
| `readThroughUntil($key, $fetch, $map, $ttlOf)` | the same, with a lifetime read from the mapped answer, for example a token kept until it expires. A lifetime of zero or less is returned as `null`. |
| `forget($key, $tags)` | drops an answer. The owner calls it when it writes, which is why `DEFAULT_TTL` can be a week. |

The answers live in the store `modulith.rpc.cache` names (`MODULITH_RPC_CACHE_STORE`, the default
store when empty). Analytics keeps iam's answer and iam forgets it, so every process must use the
same store, even when each module has its own cache for the rest.

```php
// config/modulith.php
'modules' => ['iam' => ['host' => 'https://iam.internal']],
'rpc'     => ['secret' => env('MODULITH_RPC_SECRET', env('APP_KEY'))],
```

The caller and the called process must use the same secret. It defaults to `APP_KEY`, which works
when all processes are deployed with the same `.env`. If a module is deployed with its own
`APP_KEY`, set the same `MODULITH_RPC_SECRET` on both sides, otherwise the calls are rejected with
a 403.

Calls to another process use the `http` transport unless the host names another one. To add a
driver:

```php
// config/modulith.php
'modules' => ['iam' => ['host' => ['url' => 'grpc://iam.internal', 'transport' => 'grpc']]],
'rpc'     => ['transports' => ['http' => ['driver' => 'http'], 'grpc' => ['driver' => 'grpc', 'port' => 50051]]],

app(\Modulith\Services\Rpc\RpcTransportManager::class)
    ->extend('grpc', fn ($app, array $config, string $name) => new GrpcRpcTransport($config));
```

A transport implements `invoke(Module $module, string $contract, string $method, array $arguments)`.
Its called side hands the four values to `LocalServices::call()`, as the HTTP endpoint does, so a
new transport gets the module's context and the answer's shape for free.

