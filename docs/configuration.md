# Configuration

`php artisan vendor:publish --tag=modulith-config` publishes `config/modulith.php`. The package
reads it only through `Modulith\Config\Modules`. What a module's folder must look like is not
configuration: see [Conventions](modules.md#conventions).

| Key | Default | |
|---|---|---|
| `modules` | `[]` | `'iam' => ['host' => …]`: every module, and the URL of the ones running elsewhere |
| `runs` | `env('MODULITH_RUNS', '*')` | modules this process runs |
| `paths.modules` | `apps` | directory of the modules |
| `paths.foundation` | `foundation` | directory of what modules share with each other |
| `namespaces.modules` | `Apps` | root namespace of the modules |
| `namespaces.foundation` | `Foundation` | root namespace of the foundation |
| `status_route` | `env('MODULITH_STATUS_ROUTE')` | path of the status route, `null` for none |

Calls and events are configured in `config/microservices.php`
(`php artisan vendor:publish --tag=microservices-config`): the RPC secret, transports and cache
store, the streams, the guard and the propagated context. See
[laravel-microservices' configuration](https://github.com/mk-josias/laravel-microservices/blob/main/docs/configuration.md).
Every module of `modulith.modules`, with its host, is a service there: don't declare it again. A
module adds its handlers and its streams in its own `config/microservices.php`.

## Source layout

```
src/
├── Providers/          ModulithServiceProvider · ModuleServiceProvider · FoundationServiceProvider
├── Http/               Controllers/StatusController · Middleware/SetModuleContext
├── Console/Commands/   Install · MakeModule · DeleteModule · ListModules · Doctor · PurgeModules · UnusedPackages · CacheModules · ClearModules
├── Console/Migrations/ MigrateCommand · StatusCommand · RollbackCommand · ResetCommand · RefreshCommand
│                       FreshCommand · RunsForEachModule
├── Console/            ModuleOption · ModuleGenerators · ModuleSeedCommand
├── Config/             Modules
├── Data/               Module
├── Jobs/               FailedJobProvider · BatchRepository · Databases
├── Traits/             ResolvesModule
├── Support/            ModuleDeferredCallbacks · ModuleConcurrencyDriver · ModuleTaskDispatcher · ModuleFactories
├── Exceptions/         ModuleException
├── Testing/            Boundaries · InteractsWithModules
└── Services/Modules/   ModuleRegistry · ModuleContext · ModuleColocation · ModuleRpcTransport
                        CachedShadowRegistry · DiscoveryCache · ModuleMigrations · ComposerAutoload
```

`ModuleColocation` is what makes each module a service of laravel-microservices: it tells which
modules this process runs, which module a class belongs to, and which connection each one uses.
`ModuleRpcTransport` turns a call to a module this process runs into a direct method call.
