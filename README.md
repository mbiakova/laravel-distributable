# Laravel Modulith

Laravel Modulith makes your Laravel modules microservices: each owns its database and talks to the
others only through events and contracts. Run them as one application, or split them apart
whenever you want, by setting `MODULITH_RUNS`. The image of a process can then be built without
the code of the modules it doesn't run (`modulith:purge`). The code doesn't change.

```bash
composer require mk-josias/laravel-modulith
php artisan modulith:install                      # config/modulith.php, apps/, foundation/FoundationServiceProvider.php
php artisan modulith:make-module iam --database   # apps/Iam and foundation/Iam, declared in config/modulith.php
```

[laravel-modulith-skeleton](https://github.com/mk-josias/laravel-modulith-skeleton) is the example
implementation: an application with three modules, authentication, permissions, Docker images for
one process or one per module, and tests of each module alone. Read it to see the package in use,
or start from it:

```bash
composer create-project mk-josias/laravel-modulith-skeleton my-app
```

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
| [Services in other languages](docs/other-languages.md) | how a non-PHP service emits and reads events, calls a module, answers one, and feeds a copy |
| [Configuration](docs/configuration.md) | every key of `config/modulith.php`, the source layout |

## Why

Microservices give each part of a system its own data and its own deployment. That has a price
from the first day, paid per service: servers, databases, pipelines, and the work of running them.
What it buys is often less than it seems:

- **Scaling.** Only the part under load needs to scale. Making every part a service from the
  start doesn't follow from that.
- **Infrastructure.** Services that end up in the same stack, run by the same team, cost more to
  operate than one process and gain nothing from being apart.
- **Isolation.** Splitting the code across services rarely protects it: on most projects the same
  people have access to every repository.

What is hard to add later is the separation itself: each part owning its data and talking to the
others through contracts. Where each part runs is easy to change, once that separation exists.

So with this package you write each module as if it were a separate service: it owns its
database, shares no tables and never calls another module's classes. Where the modules run is
decided by the environment. Modules that have no reason to be apart stay together in one process,
and you move out only the one whose load justifies it:

```dotenv
# at first, every module in one process
MODULITH_RUNS=*

# later, the busy module runs alone and the others stay together
MODULITH_RUNS=transactions        # process A
MODULITH_RUNS=iam,analytics       # process B
```

Because a module already owns its data and only talks through events and contracts, moving it to
its own process doesn't require a rewrite. Events go through a stream in both setups, so they
behave the same whether two modules share a process or not. The image of a process can leave out
the source code of the modules it doesn't run (`modulith:purge`), and `modulith:doctor` reports
what would stop a module from running apart, such as one module importing another's class.

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
| RPC (synchronous) | asking another module for an answer right away: reading its data, or having it perform an action whose result the caller needs | the module's own class, in its context | a signed HTTP call |

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

### The module context

Several modules can share a process, each with its own database, and their code is plain Laravel:
`User::query()`, `DB::transaction()`, no connection named anywhere. What makes this work is the
module context: before any module's code runs, the package makes that module's connection
Laravel's default one, and puts the previous one back afterwards.

```
request on /analytics/…        database.default = analytics
  └─ calls IamService::findUser()
       └─ iam's code runs      database.default = iam          the query lands in iam's database
  └─ back in analytics         database.default = analytics
outside any module             database.default = the application's connection
```

The switch happens at every way into a module's code, so nothing has to ask for it:

| A module's code is entered by | The module is |
|---|---|
| a request on one of its routes | the route's, or the controller's |
| a job, a command, an event handler, a listener it declares | the one the class belongs to |
| an RPC call, from this process or another | the one that implements the contract |
| work deferred or sent to another worker (`defer()`, `Concurrency`, Octane tasks, queued closures) | the one that started it |

The current module also travels in Laravel's `Context`, which is how it follows a job to its
worker. `ModuleContext::within($module, $callback)` does the same switch by hand, and
`inModule()` in tests. The full table of entry points is in
[Models and transactions](docs/databases.md#models-and-transactions).

## Design

### The charter

Each module is a microservice from its first line of code, and the package holds it to that, even
while it shares a process with the others.

| A microservice | How the package holds a module to it |
|---|---|
| owns its data | its own database and connections; naming another module's tables or connection is reported |
| exposes only a contract | another module's classes can't be loaded where that module doesn't run, and importing them is reported |
| talks through messages | events on a stream, or signed RPC calls on a contract, whether the modules share a process or not |
| is deployed on its own | `MODULITH_RUNS` picks the modules a process runs; `modulith:purge` removes the others' code from its image |

`modulith:doctor` and `Boundaries` check all of this in CI, so a module that would not survive
being moved to its own service fails before it is deployed.

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

Third-party packages work as they do in any Laravel application. The package uses a few of
Laravel's own extension points to give each module its database; this table says what each one
does, so you know where a package's data ends up:

| What the package does | Where | What it means for another package |
|---|---|---|
| While a module's code runs, `database.default` is that module's connection | `ModuleContext` | A package that uses the default connection (a media library, an activity log, permissions) stores its rows in the database of the module that calls it, so each module has its own. Its tables are there because its migrations run in every module database. A package that should keep one store for the whole application (Telescope, Pulse) is given the application's connection in its own config file. |
| `migrate`, `migrate:status`, `migrate:rollback`, `migrate:reset`, `migrate:refresh` and `migrate:fresh` run once per database | `ModulithServiceProvider::registerModuleMigrations()` | A package's migrations, loaded with `loadMigrationsFrom()` or published to `database/migrations`, run in each database with nothing to configure. With an explicit `--database` or `--path`, the commands behave exactly as Laravel's. A package that replaces these same commands would overlap with this, which is rare; the one registered last is used. |
| Failed jobs and job batches are stored per module, with the `database` drivers | `registerQueueDatabases()` | They go to the database of the module owning the job. Other drivers (Horizon, `file`, DynamoDB) are untouched. |
| The `database` cache, queue and session drivers stay on the application's connection | `register()` | Laravel leaves their `connection` empty, meaning "the default one". Since the default is the current module's, their tables would be looked for in a module's database. The package fills an empty value with the application's connection, so cache, jobs and sessions stay in one place. A value you set yourself is kept. |
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
| Built for | organising code in modules | module boundaries and events inside one application | modules that are microservices: one codebase, deployed together or apart |
| Data | one shared database | one datasource | one database per module |
| Between modules | direct calls | events and outbox | event stream with an ordered outbox, and RPC |
| Moving a module to its own service | rewrite | new application | `MODULITH_RUNS` |
| The image of one module | the whole codebase | the whole application | only that module's code (`modulith:purge`) |

### Out of scope

- Turning modules on or off at runtime. Which modules run is decided at boot.
- A lockfile per module: one process loads one version of a library, so there is one `vendor/` and
  one lockfile. A module can still declare its own dependencies, see
  [The dependencies of a module](docs/modules.md#the-dependencies-of-a-module).
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

    public function version(): int { return 1; }   // raised when the shape of the payload changes
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

This event is version 1. When the shape of a payload changes, the event declares a new version and
how to read the old ones, so events already in the stream stay readable: see
[Versioning a payload](docs/events.md#versioning-a-payload).

Then check that every module could run apart:

```bash
php artisan modulith:doctor
```

```
Boundary crossed: apps/Analytics/app/Models/Report.php: Apps\Iam\Models\User
[iam] runs elsewhere and serves Foundation\Iam\Contracts\IamService, but modulith.modules.iam.host is not set.
```

It reports a module that imports another module's class or names its tables, an undeclared module,
a missing connection, a missing host or RPC secret, and exits with a non-zero code if it finds
anything. The full list is in [Checking the application](docs/modules.md#checking-the-application).

## Testing

```bash
composer check   # pint + phpstan (level 6) + pest
```

## License

MIT. See [LICENSE](LICENSE).
