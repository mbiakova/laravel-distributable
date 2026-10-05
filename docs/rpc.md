# Calls between modules (RPC)

Calls between modules are [laravel-microservices' RPC](https://github.com/mk-josias/laravel-microservices/blob/main/docs/rpc.md):
each module is a service, named after it. This page covers what a module adds; the signature, the
status codes, the answer cache and custom transports are described there.

The module that answers puts the contract and an `RpcService` in its foundation, so every caller
has them whether the module runs in the same process or not. It implements the contract in its own
directory:

```
foundation/Iam/Contracts/IamService.php      the contract
foundation/Iam/Services/IamRpcService.php    extends RpcService: every call to iam goes through it
foundation/FoundationServiceProvider.php     binds IamService to IamRpcService
apps/Iam/app/Services/IamService.php         implements the contract with iam's own data
apps/Iam/app/Providers/IamServiceProvider    declares that implementation in $services
```

```php
// foundation/FoundationServiceProvider.php
final class FoundationServiceProvider extends \Distributable\Providers\FoundationServiceProvider
{
    protected array $rpc = [
        IamService::class => IamRpcService::class,
    ];
}

// apps/Iam/app/Providers/IamServiceProvider.php
final class IamServiceProvider extends \Distributable\Providers\ServiceProvider
{
    protected array $services = [
        \Foundation\Iam\Contracts\IamService::class => \Apps\Iam\Services\IamService::class,
    ];
}

// foundation/Iam/Services/IamRpcService.php
final class IamRpcService extends \Microservices\Services\Rpc\RpcService implements IamService
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
```

The `RpcService` calls the module whose foundation holds it: `Foundation\Iam\…` is iam's. Other
modules type-hint `Foundation\Iam\Contracts\IamService` and don't need to know where iam runs.

## One path, wherever the module runs

```
analytics ─► IamService (the contract) ─► IamRpcService::call('findUser', ['id' => 1])
                                                    │
                                 does iam run in this process (RUN_MODULES)?
           ┌────────────────────────────────────────┴───────────────────────────────────────┐
           │ yes: a direct method call                                                      │ no: POST {iam's host}/iam/rpc/findUser, signed
           ▼                                                                                ▼
   iam's $services: Apps\Iam\Services\IamService                         the same call, in iam's process
   called in iam's context, so its queries land in iam's database
```

When the two modules share a process, the call is a direct method call: no HTTP, no signature,
nothing on the network. It becomes a signed HTTP call only between two processes. On both paths
the implementation runs in iam's [context](../README.md#the-module-context), so it never has to
switch to its own database.

The module writes no route: the package serves `POST {module}/rpc/{method}` for every module the
process runs. A module's host comes from `distributable.modules`; the secret, the cache store and the
transports are in `config/microservices.php`. A contract with no `RpcService` stays bound to the
module's implementation, and works only in a process that runs that module.
