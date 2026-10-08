# Configuration

`php artisan vendor:publish --tag=distributable-config` publishes `config/distributable.php`. The package
reads it only through `Distributable\Config\Modules`. What a module's folder must look like is not
configuration: see [Conventions](modules.md#conventions).

| Key | Default | |
|---|---|---|
| `modules` | `[]` | `'iam' => ['host' => …]`: every module, and the URL of the ones running elsewhere |
| `runs` | `env('RUN_MODULES', '*')` | modules this process runs |
| `paths.modules` | `apps` | directory of the modules |
| `paths.foundation` | `foundation` | directory of what modules share with each other |
| `namespaces.modules` | `Apps` | root namespace of the modules |
| `namespaces.foundation` | `Foundation` | root namespace of the foundation |
| `status_route` | `env('MODULES_STATUS_ROUTE')` | path of the status route, `null` for none |

Calls and events are configured in `config/microservices.php`
(`php artisan vendor:publish --tag=microservices-config`): the RPC secret, transports and cache
store, the streams, the guard and the propagated context. See
[laravel-microservices' configuration](https://github.com/mbiakova/laravel-microservices/blob/main/docs/configuration.md).
Every module of `distributable.modules`, with its host, is a service there: don't declare it again. A
module declares its handlers in `$handlers` of its service provider, and its streams in its own
`config/microservices.php`.

## Source layout

```
src/
├── Providers/          DistributableServiceProvider · ServiceProvider · FoundationServiceProvider
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
