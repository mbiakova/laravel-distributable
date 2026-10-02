# Laravel Modulith

Laravel Modulith splits a Laravel application into modules. Each module has its own database and
talks to the other modules only through events and RPC calls. You can run all the modules in one
process, or move some of them to their own process by setting `MODULITH_RUNS`. The code doesn't
change.

```bash
composer require mk-josias/laravel-modulith
php artisan modulith:install                      # config/modulith.php, apps/, foundation/FoundationServiceProvider.php
php artisan modulith:make-module iam --database   # apps/Iam and foundation/Iam, declared in config/modulith.php
```

To start a new application with three example modules instead:
`composer create-project mk-josias/laravel-modulith-skeleton my-app`.

Requires PHP 8.4+ and Laravel 12 or 13. The package has no other runtime dependency.

- [Why](#why)
- [How it works](#how-it-works)
- [Design](#design)
- [Quick start](#quick-start)

The rest of the documentation is in `docs/`:

| | |
|---|---|
| [Modules](docs/modules.md) | declaring a module, generating code in it, conventions, `modulith:doctor` |
| [A database per module](docs/databases.md) | connections, the module context, migrations, queued jobs |
| [Events](docs/events.md) | the envelope, versioning, transports, the outbox, consuming |
| [Calls between modules (RPC)](docs/rpc.md) | contracts, `RpcService`, the read-through cache |
| [Read-only copies (shadows)](docs/shadows.md) | keeping another module's rows locally |
| [Services in other languages](docs/other-languages.md) | the Redis entry and the RPC signature, for a non-PHP service |
| [Configuration](docs/configuration.md) | every key of `config/modulith.php`, the source layout |

## Why

Microservices give each part of a system its own data and its own deployment, but you pay for
every service from the first day: servers, databases, pipelines. Most applications don't have the
traffic to justify that at the start.

With this package you write each module as if it were a separate service: it owns its database,
shares no tables and never calls another module's classes. Where the modules run is decided by
the environment, and you can change it later:

```dotenv
# at first, every module in one process
MODULITH_RUNS=*

# later, the busy module runs alone and the others stay together
MODULITH_RUNS=transactions        # process A
MODULITH_RUNS=iam,analytics       # process B
```

Because a module already owns its data and only talks through events and contracts, moving it to
its own process doesn't require a rewrite. Events go through a stream in both setups, so they
behave the same whether two modules share a process or not.

## How it works

```
                              one codebase
┌──────────────────────────────────────────────────────────────────────┐
│ apps/Iam              apps/Analytics            apps/Transactions    │
│  own database          own database              own database        │
└──────────────────────────────────────────────────────────────────────┘
          │ MODULITH_RUNS picks which modules each process boots
          ▼
┌─────────────── process A ───────────────┐   ┌──── process B ────┐
│ iam · analytics                         │   │ transactions      │
└─────────────────────────────────────────┘   └───────────────────┘
       │            ▲         │                        ▲
       │ events     │         └── RPC (signed HTTP) ───┘
       ▼            │
   ┌──────── stream (redis · queue · yours) ────────┐
   └────────────────────────────────────────────────┘
```

Modules communicate in two ways:

| Mode | Used for | Same process | Different processes |
|---|---|---|---|
| Event stream (asynchronous) | announcing that something happened | through the stream | through the stream |
| RPC (synchronous) | reading data another module owns | the module's own class, in its context | a signed HTTP call |

Shadows, the read-only copies described below, are built on events.

When the application boots, the package:

1. reads the modules declared in `config/modulith.php`, and autoloads the ones this process runs
   under `Apps\{Module}\`;
2. registers the service provider of every module this process runs. The provider merges the
   module's config files, loads its routes, translations and commands, and records the
   implementation of each contract the module answers;
3. registers the foundation's service provider, which binds every RPC contract to its
   `RpcService`. The `RpcService` reaches the module in this process when it runs here, over the
   network otherwise.

A module that runs in another process has no config, routes or handlers here. Only its RPC
contracts are bound.

Each request, job, command and event handler runs in the context of the module it belongs to, and
that module's database connection becomes the default one ([details](docs/databases.md#models-and-transactions)).

## Design

### What the package includes

The package only contains what is needed for modules to be deployed separately. How you write
the application itself (response formats, DTOs, repositories, exceptions, authentication) is up to
you.

A feature is in the package when getting it wrong would break one of its guarantees. Module code
running outside its module's context would write to the wrong database, so the module context is
part of the package. A module without a repository breaks nothing, so repositories aren't.

### Extension points

| Contract | Default | Replace it with |
|---|---|---|
| `Contracts\Stream\Transport`, the event stream | `redis`, `queue`, `array`, `null` | `TransportManager::extend()` |
| `Contracts\Rpc\RpcTransport`, calls between modules | `HttpRpcTransport` | `RpcTransportManager::extend()` |
| `Contracts\Stream\Handler`, a module's reaction to an event | none | a class listed under `events.listen` |
| `Contracts\Stream\Versioned`, the versions of an event's payload | none: every event is version 1 | a payload class in the owner's foundation, mapped in `FoundationServiceProvider::$payloads` |
| `Services\Rpc\RpcService`, a module's client in the other processes | none | a subclass in `foundation/{Module}/Services`, mapped in `FoundationServiceProvider::$rpc` |
| `Models\ShadowModel`, a read-only copy | none | an abstract subclass in the owner's foundation, extended in each keeper |

The package defines the envelope format, which is why transports can be swapped.

### Working with other packages

The package changes a few things in Laravel. A third-party package keeps working unless it relies
on one of them:

| What the package changes | Where | What it means for another package |
|---|---|---|
| `database.default` follows the module the code runs in | `ModuleContext` | A package writing through the default connection (media, activity log, permissions) writes into the current module's database. Its migrations run in every module database, so its tables are there. A package that must keep one global store (Telescope, Pulse) sets its own `connection` key to the application's connection. |
| `migrate`, `migrate:status`, `migrate:rollback`, `migrate:reset`, `migrate:refresh`, `migrate:fresh` | `ModulithServiceProvider::registerModuleMigrations()` | They run once per database, including the migrations packages load with `loadMigrationsFrom()` and the ones you publish to `database/migrations`. Another package that also replaces these commands conflicts: the last one registered wins. |
| `queue.failer` and the job batch repository, with the `database` drivers only | `registerQueueDatabases()` | Failed jobs and batches go to the database of the module owning the job. Other drivers (Horizon, `file`, DynamoDB) are left alone. |
| The database connection of the `database` cache, queue and session drivers | `register()` | It is pinned to the application's connection when the config leaves it empty, so it never follows a module. |
| `DeferredCallbackCollection`, the Concurrency `process` driver, Octane's `DispatchesTasks` | `carryTheModuleIntoDeferredWork()` | Work started in a module keeps its module. A package rebinding one of them removes that, for its own work. |
| Module providers register on `booting`, after every package provider | `registerLocalModuleProviders()` | A module can override what a package binds. |
| `Factory::guessFactoryNamesUsing()` and `guessModelNamesUsing()` | `Support\ModuleFactories::register()` | A module model finds its factory in the module; any other class keeps Laravel's rule. A package or an application that sets its own resolver replaces this one, and can delegate to `ModuleFactories`. |
| Every Artisan command gets a `--module` option, unless it already has one | `Console\ModuleOption` | A package's command can be run in a module's context. During a `make:*` command with `--module`, the application path, database path, config path and namespace are the module's, then restored (`Console\ModuleGenerators`). Without `--module`, nothing changes. |
| `db:seed` is replaced | `Console\ModuleSeedCommand` | In a module's context it runs the module's seeders; outside one it is Laravel's command, unchanged. |
| A module's `$listen` wraps each listener in the module's context | `ModuleServiceProvider::registerModuleListeners()` | A module can listen to a package's events (`Login`, a media event) and still write to its own database. A listener registered another way (`Event::listen()` in `boot()`, discovery, a subscriber) runs in the context of whoever dispatched the event. |
| A class of a module this process doesn't run throws `ModuleException` from the autoloader | `autoloadModules()` | `class_exists()` on such a class throws instead of returning `false`. A package probing classes (discovery, morph maps) must only meet classes of the modules this process runs. |

Publishing a package's migrations with `vendor:publish` puts them in `database/migrations`: they then run in
the application's database and in every module database, like your own root migrations. Move a
published migration to a module's `database/migrations` when only that module uses the package.

A package that keeps one store for the whole application belongs to one module. Laravel Sanctum is
the usual case: `PersonalAccessToken::findToken()` reads the default connection, so a token issued
in `iam` is not in `analytics`' database, and its `tokenable` is iam's own model, a class the other
processes don't load. Install it in `iam` only, and let the other modules validate tokens through
iam's contract:

```php
// apps/Iam/app/Services/IamService.php: the User model uses HasApiTokens
public function findUserByToken(string $token): ?array
{
    return $this->present(PersonalAccessToken::findToken($token)?->tokenable);
}
```

Move Sanctum's published migration to `apps/Iam/database/migrations`, so `personal_access_tokens`
exists in iam's database only. The same holds for a package that loads its data once per process
under one cache key, such as spatie/laravel-permission: keep it in one module.

### Compared to other packages

| | nwidart/laravel-modules | Spring Modulith | laravel-modulith |
|---|---|---|---|
| Goal | organise code in modules | module boundaries and events | organise code in modules and deploy them separately |
| Data | one shared database | one datasource | one database per module |
| Between modules | direct calls | events and outbox | event stream with an ordered outbox, and RPC |
| Moving a module to its own service | rewrite | new application | `MODULITH_RUNS` |

### Out of scope

- Turning modules on or off at runtime. Which modules run is decided at boot.
- A `composer.json` per module while modules are deployed together: there is one `vendor/` and
  one lockfile.
- Orchestration (Kubernetes, proxies). That belongs to your infrastructure.
- Enforcing a code style.

## Quick start

`app/` is still your Laravel application. Each module lives in `apps/`.

```
config/modulith.php                   declares the modules: 'modules' => ['iam' => [], …]

apps/Iam/
├── app/                              Apps\Iam\, laid out like a Laravel app
│   ├── Providers/IamServiceProvider.php
│   ├── Models/User.php
│   └── Events/UserRegistered.php
├── config/
│   ├── database.php                  its connections; if the file exists, the module has a database
│   └── modulith.php                  the events it listens to
├── database/migrations/
└── routes/api.php                    served under /iam/api/…

foundation/
├── FoundationServiceProvider.php     maps each contract to its RpcService
└── Iam/                              Foundation\Iam\, what iam shares with the other modules
    ├── Contracts/IamService.php
    ├── Services/IamRpcService.php    how another process calls iam
    ├── Shadows/UserShadow.php
    └── database/shadows/             the migration of the copies of iam's tables
```

```php
// config/modulith.php
'modules' => [
    'iam'       => [],
    'analytics' => [],
],

// apps/Iam/app/Providers/IamServiceProvider.php
final class IamServiceProvider extends \Modulith\Providers\ModuleServiceProvider {}

// apps/Iam/app/Models/User.php: plain Eloquent, it uses iam's database because it runs in iam
final class User extends \Illuminate\Database\Eloquent\Model {}
```

```php
// apps/Iam/config/database.php, merged into config/database.php when iam boots
return ['connections' => [
    'iam'       => ['driver' => 'pgsql', 'database' => 'iam', 'username' => 'iam_app'],   // reads and writes
    'iam_owner' => ['driver' => 'pgsql', 'database' => 'iam', 'username' => 'iam_owner'], // creates and alters tables
]];
```

```bash
php artisan migrate   # migrates the application's database, then each module's
```

Emit an event from one module and handle it in another:

```php
final class UserRegistered extends \Modulith\Events\Event
{
    public function __construct(private readonly int $id) {}

    public function name(): string { return 'iam.user.registered'; }

    public function payload(): array { return ['id' => $this->id]; }
}

app(\Modulith\Contracts\Stream\Bus::class)->emit(new UserRegistered($user->id));
```

```php
// apps/Analytics/app/Handlers/RecordSignup.php
final class RecordSignup implements \Modulith\Contracts\Stream\Handler
{
    public function handle(string $name, array $payload): void { /* ... */ }
}

// apps/Analytics/config/modulith.php
return ['events' => ['listen' => ['iam.user.registered' => [RecordSignup::class]]]];
```

```bash
php artisan modulith:events:consume --module=analytics
```

## Testing

```bash
composer check   # pint + phpstan (level 6) + pest
```

## License

MIT. See [LICENSE](LICENSE).
