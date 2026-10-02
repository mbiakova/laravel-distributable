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
- [Modules](#modules)
- [A database per module](#a-database-per-module)
- [Events](#events)
- [Calls between modules (RPC)](#calls-between-modules-rpc)
- [Read-only copies (shadows)](#read-only-copies-shadows)
- [Services in other languages](#services-in-other-languages)
- [Configuration](#configuration)

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
that module's database connection becomes the default one ([details](#models-and-transactions)).

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

## Modules

### Declaring a module

A module is a name in `modulith.modules`. Its folder is `apps/` followed by the name in
StudlyCase, and everything else is derived from that folder. The configuration only holds what the
folder can't tell, such as the URL of a module that runs elsewhere:

```php
// config/modulith.php (php artisan vendor:publish --tag=modulith-config)
'modules' => [
    'iam'       => ['host' => env('MODULITH_IAM_HOST')],
    'analytics' => [],
],
'paths'      => ['modules' => 'apps', 'foundation' => 'foundation'],
'namespaces' => ['modules' => 'Apps', 'foundation' => 'Foundation'],
```

| | Module `Iam`, with the defaults |
|---|---|
| name | `iam`, the directory name in snake_case (`^[a-z][a-z0-9_]*$`) |
| namespace | `Apps\Iam`, autoloaded from `apps/Iam/app` by the package: the application needs no `composer.json` entry |
| provider | `Apps\Iam\Providers\IamServiceProvider` |
| database | yes, because `apps/Iam/config/database.php` exists |

```bash
php artisan modulith:make-module point_of_sale [--database]   # creates apps/PointOfSale and foundation/PointOfSale, and declares it
php artisan modulith:delete-module point_of_sale [--force]    # deletes its folders, its declaration and its composer.json entries; its database is left as it is
php artisan modulith:list                                     # lists the modules, where they run, their database and host
php artisan modulith:purge                                    # deletes the folder of the modules this process doesn't run
php artisan modulith:doctor                                   # checks that the modules can run, see "Checking the application"
```

`modulith:make-module` also adds the module and the foundation to the `autoload.psr-4` of your
`composer.json`, and the module's tests to `autoload-dev`. The application doesn't read those entries: they are for the tools that read
`composer.json` without booting Laravel, such as your IDE. They follow `paths.modules` and
`namespaces.modules`; if you change these later, `modulith:doctor` reports the entries gone stale.
A module outside the project gets none. `modulith:purge` can delete a module the entries name:
Composer accepts an entry whose folder is gone.

### Generating code in a module

Laravel's own `make:*` commands take `--module`. They then write in the module, under its namespace,
exactly what they write in `app/` without the option:

```bash
php artisan make:model Invoice -mf --module=billing        # apps/Billing/app/Models/Invoice.php, its migration and its factory
php artisan make:controller InvoiceController --module=billing
php artisan make:migration add_paid_at_to_invoices --module=billing
```

| Command | Where it writes |
|---|---|
| `make:model`, `controller`, `request`, `resource`, `job`, `event`, `listener`, `policy`, `rule`, `command`, `mail`, `notification`, `enum`, `class`… and the generators other packages add (`make:data`) | `{module}/app/…`, under the module's namespace |
| `make:migration`, and `-m` on `make:model` | `{module}/database/migrations` |
| `make:factory`, `make:seeder` | `{module}/database/factories` and `database/seeders`, under `{Namespace}\Database\Factories` and `\Seeders` |
| `make:config` | `{module}/config` |
| `make:view`, `make:component`, and `--markdown` on `make:mail` and `make:notification` | `{module}/resources/views`; the class that renders the view names it with the module: `view('billing::components.alert')` |
| `make:test`, and `--test` on the other generators | `{module}/tests/Feature` or `tests/Unit`, under `{Namespace}\Tests` |
| `make:provider` | no `--module`: a module has one provider, and the package registers it |

A module's tests extend your `Tests\TestCase`. PHPUnit runs them once `phpunit.xml` lists them:

```xml
<testsuite name="Modules">
    <directory>apps/*/tests</directory>
</testsuite>
```

Keep in a module the tests that only need that module, and in `tests/` the ones that cross modules:
`Boundaries` reads a module's tests like the rest of its code.

A model's factory is found without any code: `Invoice::factory()` resolves
`Apps\Billing\Database\Factories\InvoiceFactory`, and that factory resolves its model. Laravel's
`#[UseFactory]` on a model still wins, and so does a resolver of your own; yours can fall back on
`Modulith\Support\ModuleFactories::factoryName()` and `modelName()`.

### Running a command in a module

`--module` is on every Artisan command, and always names one module:

| Command | What `--module=billing` does |
|---|---|
| `make:*` | generates in the module (above) |
| `migrate`, `migrate:fresh`… | migrates that module's database only; it can be repeated |
| `db:seed` | runs `Apps\Billing\Database\Seeders\DatabaseSeeder`; `--class=InvoiceSeeder` names a seeder of the module |
| any other command, yours or a package's | runs in the module's context, on its database |

```bash
php artisan tinker --module=billing                                 # Invoice::count() reads billing's database
php artisan model:show 'Apps\Billing\Models\Invoice' --module=billing
php artisan db:seed --module=billing
```

Without the option, a command that isn't a module's own runs in no module: `model:show` on a
module's model then looks for its table in the application's database.

`migrate --seed` and `migrate:fresh --seed` seed each database with its own seeders: the
application's `Database\Seeders\DatabaseSeeder` once, then each module's `DatabaseSeeder` in that
module's run. A module without one is skipped.

### Conventions

Only the four roots are configuration. Everything inside them is a convention: the package reads
it from the folder and the namespace, and no option changes it.

| | Configurable | Fixed by convention |
|---|---|---|
| Where the modules live | `paths.modules` (`apps`), `namespaces.modules` (`Apps`) | a module is `{paths.modules}/{Name}`, its name is `{Name}` in snake_case |
| Where the foundation lives | `paths.foundation` (`foundation`), `namespaces.foundation` (`Foundation`) | what module `iam` shares is `{paths.foundation}/Iam`, under `Foundation\Iam\` |
| A module's code | | `app/`, under `{namespaces.modules}\{Name}\` |
| A module's provider | | `app/Providers/{Name}ServiceProvider.php` |
| A module's database | | it has one exactly when a connection named `{name}` exists, declared in the module's `config/database.php` or the root one, next to `{name}_owner` |
| A module's config, routes, translations, migrations | | `config/*.php`, `routes/{surface}.php` (served under `{name}/{surface}`), `lang/`, `database/migrations/` |
| A module's commands | | every `Illuminate\Console\Command` under `app/` |
| A module's factories and seeders | | `database/factories/` under `{namespaces.modules}\{Name}\Database\Factories\`, `database/seeders/` under `…\Database\Seeders\` |
| A module's views | | `resources/views/`, under the view namespace `{name}::` |
| A module's tests | | `tests/`, under `{namespaces.modules}\{Name}\Tests\` |
| An RPC service | | under `Foundation\{Name}\`: the segment after the root names the module it calls |
| The migration of a copy | | `{paths.foundation}/{Owner}/database/shadows/` |
| The foundation's provider | | `{namespaces.foundation}\FoundationServiceProvider` |

They are fixed on purpose. Because the folder says everything, a module is found without being
registered, `modulith:purge` can delete a whole folder, `Boundaries` knows which module owns a file,
and an RPC service knows which module it calls. A setting for each would make those guarantees
depend on configuration that every process must get right.

### Which modules a process runs

```dotenv
MODULITH_RUNS=*                       # every module (the default)
MODULITH_RUNS=transactions,analytics  # only these
```

`Modulith\Services\Modules\ModuleRegistry` gives you both lists: `all()` returns every declared
module and `local()` the ones this process runs. `get($name)` throws on an unknown name, and
`forClass($class)` returns the module a class belongs to, based on its namespace.

In production, `php artisan optimize` runs `modulith:cache`, which writes what the module folders
tell (namespaces, databases, copies and shadow sources) to `bootstrap/cache/modulith.php`, and the
application reads that file instead of the folders. `optimize:clear` (or `modulith:clear`) deletes
it. The cache never decides which modules exist: `modulith.modules` does.

### Modules this process doesn't run

The package only autoloads the classes of the modules in `MODULITH_RUNS`, plus the foundation. A
class of any other module is never loaded, even if its file is on disk: using it throws a
`ModuleException`.

```
[Apps\Iam\Services\IamService] belongs to module [iam], which this process does not run (MODULITH_RUNS): go through its foundation contract or an event.
```

This catches, at runtime, a call that `Boundaries` would have caught in your tests. A process still
knows that the other modules exist, because `config/modulith.php` declares them: it listens to
their events, and calls them through the RPC services of the foundation.

When you build an image for some modules only, you can delete the folders of the others:

```bash
MODULITH_RUNS=analytics php artisan modulith:purge --force
```

Run it in your Dockerfile, after copying the code and before `composer dump-autoload`. Starting an
image with a module in `MODULITH_RUNS` whose folder was purged fails at boot, with the name of the
module.

With `MODULITH_STATUS_ROUTE=/`, the process returns the modules it runs:

```json
{ "message": "Hello from laravel-modulith", "modules": ["transactions", "analytics"], "status": "ok" }
```

### The module service provider

A provider that extends `Modulith\Providers\ModuleServiceProvider` loads the following from the
module directory:

| Source | What happens |
|---|---|
| `config/*.php` | Merged into the root config file with the same name (see below). Once `config:cache` has run, the cache already holds the merge and the files are not read again. |
| `routes/{name}.php` | Loaded under the `{module}/{name}` prefix, in the `{name}` middleware group if the application defines one. |
| `lang/` | Loaded under the module name: `__('iam::messages.hello')`. |
| `resources/views/` | Loaded under the module name: `view('iam::welcome')`. Anonymous components of `resources/views/components` and class components of `app/View/Components` are `<x-iam::alert />`. |
| Artisan commands | Every `Illuminate\Console\Command` in `app/` is registered (console only). |
| `$listen` on the provider | The Laravel events the module listens to, written as in `EventServiceProvider::$listen`. Each listener runs in the module, whichever module dispatched the event; a queued one keeps working as Laravel queues it, and an after-commit one runs once the transaction commits, still in the module. |

How the config merge works, and what it can't do:

| Case | Result |
|---|---|
| a list (`['a', 'b']`) | gains the items it lacks; a module can't remove or replace an item, so set the whole list in the root config |
| any other array, integer keys included (`[404 => …]`) | merged key by key |
| a scalar two local modules set differently | the module registered last wins in one process, while each keeps its own once they run apart: `modulith:doctor` reports it |
| when it runs | as the module's provider registers, after every package provider: a package reading its config in its own `register()` doesn't see the module's values |
| its scope | the whole process: a module overriding a package's config changes it for every module running there |

A module's Laravel listeners go in `$listen`:

```php
final class IamServiceProvider extends ModuleServiceProvider
{
    protected array $listen = [
        \Illuminate\Auth\Events\Login::class => [\Apps\Iam\Listeners\RecordLogin::class],
    ];
}
```

Laravel's own event discovery doesn't see a module's `app/Listeners`: it names
`apps/Iam/app/Listeners/X.php` `Apps\Iam\app\Listeners\X`. Declare the listeners in `$listen`.
In a test, `Event::assertListening()` only sees a closure for them: use
`$this->assertListeningInModule(Login::class, RecordLogin::class)` from `InteractsWithModules`.

### The foundation

A module never uses another module's classes, because that import would break as soon as the
modules are deployed separately. What a module shares with the others (its RPC contracts and
services, the shape of its copies, its event names) goes in `foundation/{Module}/`, autoloaded as
`Foundation\{Module}\` (`modulith.paths.foundation`, `modulith.namespaces.foundation`). Any module can
use the foundation, and the foundation uses no module.

```
apps/Analytics ──► foundation/Iam ◄── apps/Iam
       └──────── never ──────────────┘
```

The package checks this rule for you, see [Checking the application](#checking-the-application).

### Checking the application

The package has two checks. Both report the same boundary violations, but they are meant for
different moments.

`modulith:doctor` checks that the modules can run with the current configuration. Run it when
you deploy, or in CI with the production environment:

```bash
php artisan modulith:doctor
```

| It reports | Example |
|---|---|
| a folder of `apps/` that `modulith.modules` doesn't declare | `[apps/Billing] is not declared in modulith.modules.` |
| a module whose service provider class doesn't exist | `[gateway] provider Apps\Gateway\Providers\GatewayServiceProvider does not exist.` |
| a local module with a database but no declared connection | `[iam] connection [iam_owner] is not declared.` |
| a module running elsewhere that serves a contract, with no host | `[iam] runs elsewhere and serves Foundation\Iam\Contracts\IamService, but modulith.modules.iam.host is not set.` |
| RPC contracts declared while the secret is empty | `Modules serve RPC contracts but modulith.rpc.secret is empty: set MODULITH_RPC_SECRET or APP_KEY.` |
| a module using another module's classes, or the foundation or the application (`app/`, `routes/`, `config/`) using a module | `Boundary crossed: apps/Analytics/app/Models/Report.php: Apps\Iam\Models\User` |
| a module naming another module's connection, or a table that module's migrations create | `Boundary crossed: apps/Analytics/app/Models/Report.php: 'iam_users'` |
| two local modules setting one config key to different values | `Modules [analytics, iam] set config [iam.flag] to different values: in one process, the module registered last wins.` |

It lists every problem and exits with a non-zero code if there is at least one.

`Modulith\Testing\Boundaries` is the architecture check on its own, for your test suite. It
doesn't depend on the configuration: it reads the PHP files of every module, of the foundation and
of the application's `app/`, `routes/` and `config/`.

```php
// tests/Architecture/BoundariesTest.php
it('keeps the modules apart', function () {
    expect(app(\Modulith\Testing\Boundaries::class)->violations())->toBe([]);
});
```

## A database per module

### Connections

A module with a database has two connections, named after it. There is no mapping to fill in: the
name is the link, so module `iam` uses the connections `iam` and `iam_owner`, and no other.

| Connection | Role |
|---|---|
| `{module}` | Used at runtime to read and write rows. |
| `{module}_owner` | Owns the tables and can create and alter them. Only the `migrate` commands use it. |

Declare them where you prefer; the module has a database as soon as the connection `{module}` exists:

| Where | Result |
|---|---|
| the module's `config/database.php` | merged into `database.connections`; the module carries its own setup |
| the root `config/database.php`, next to `sqlite` and `pgsql` | the same, with every connection in one file |
| nowhere | the module has no database of its own: it uses the application's, and its migrations run there |

The connections can point to separate databases on one server, separate schemas of one database,
separate servers, or sqlite files in tests. The code is the same in every case. Several modules can
also share one database; the outbox filters its rows by emitting module.

There are no foreign keys between modules. Reference another module's rows by id, or keep a
[shadow](#read-only-copies-shadows) of them.

### Models and transactions

Models are plain Eloquent models, including `User extends Authenticatable`. Requests to a module's
routes, and jobs, commands and event handlers whose class belongs to a module, run in that module's
context: its connection becomes the default one. Models, `DB::`, `DB::transaction()` and `Schema::`
then use the module's database without any extra code. If several modules share a database, give
their tables distinct names (`protected $table = 'iam_users'`).

```php
// apps/Iam/app/Actions/RegisterUser.php, called from an iam route, job or command
final class RegisterUser
{
    public function execute(string $name): User
    {
        return DB::transaction(fn () => User::query()->create(['name' => $name]));   // iam database
    }
}
```

| Entry point | Hook | Module |
|---|---|---|
| request on `/{module}/…` | `Http\Middleware\SetModuleContext` on the module's routes | the route's module |
| any route whose controller is a module's class, wherever the route is declared | `RouteMatched` | the module of the controller class |
| job | `Queue::before` | the module of the job class |
| command | `CommandStarting` | the module of the command class |
| event handler | `Dispatcher`, around each handler | the module of the handler class |
| Laravel event listener declared in a module's `$listen` | `ModuleServiceProvider`, around each listener | the module that declares it |
| RPC call, from this process or another | `LocalServices`, around the contract's method | the module that implements the contract |
| queued closure, or any job class outside a module | `Queue::before`, from the Context Laravel carries into the job | the module that queued it |
| `defer(fn () => …)` | a `DeferredCallbackCollection` that wraps each callback | the module that deferred it |
| `Concurrency::run()` (`process` driver) | a wrapper around the driver: the child process gets only the closure | the module that started it |
| `Octane::concurrently()` | a `DispatchesTasks` bound by the package, for the task workers | the module that started it |

The last line is why a module never switches databases itself: when analytics calls
`IamService::findUser()` and iam runs in the same process, iam's implementation still runs in iam's
context and reads iam's database. The context is restored once the method returns.

For your own deferred work, `ModuleContext::bind($task)` returns a closure that runs `$task` in the
current module wherever it runs later. It captures only the module's name, so it can be serialized.
A closure sent to another process (Octane tasks, `Concurrency`, queued closures) is rebuilt by
`laravel/serializable-closure` from its line in the file: keep one closure per line, or the worker
may rebuild the wrong one.

The database drivers of the cache, the queue and the session would otherwise keep the connection
of whichever module used them first. When their `connection` is empty, the package sets it to the
application's default connection.

Outside a module, the application's default connection is used. Code that isn't in a module (your
`app/`, a route closure, another package's route, Tinker) therefore never uses a module's models:
`Apps\Iam\Models\User::query()` there reads the application's database, where `iam_users` doesn't
exist, and throws `ModuleException` once iam runs in another process. It goes through the module's
contract, which runs in the module's context and still works when the module runs elsewhere:

```php
app(\Foundation\Iam\Contracts\IamService::class)->findUser($id);   // never Apps\Iam\Models\User::find($id)
```

`Services\Modules\ModuleContext::current()` returns the current module (`Data\Module`) or null,
and `within($module, $callback)` runs a callback in a module's context.

In tests, use `Modulith\Testing\InteractsWithModules`:

```php
$user = $this->inModule('iam', fn () => User::query()->create(['name' => 'ada']));   // iam database
```

The trait also turns on Laravel's console events in tests, so `$this->artisan('iam:sync')` runs
the command in its module's context, as the real console does.

### Migrations

The package extends Laravel's migrate commands so they run once per database. This also applies
to `RefreshDatabase` in your tests.

```bash
php artisan migrate | migrate:status | migrate:rollback | migrate:reset | migrate:refresh | migrate:fresh  [--module=*]
```

| Database | Migrations |
|---|---|
| the application's default database | `database/migrations` and the migrations other packages load with `loadMigrationsFrom()`, plus the migrations of modules that have no database |
| each local module's database, on `{module}_owner` | the package's tables (`event_publications`, `event_consumptions`), `database/migrations`, the migrations other packages load, the module's `database/migrations` and the shadow migrations of the copies it keeps |

`--module` runs the command for those modules only and skips the application's database. With an
explicit `--database` or `--path`, the command behaves like the normal Laravel command. Each
database keeps its own migration history.

### Queued jobs

Failed jobs and job batches are stored in the database of the module the job class belongs to.
`queue:failed`, `queue:retry` and `Bus::findBatch()` read from the databases of the modules this
process runs. This works when Laravel stores them in a database (the `database-uuids` failer and
database batches). The tables come from the application's `database/migrations`, which run in every
module database.

## Events

### How an event travels

Events are asynchronous: a module announces that something happened, and the modules that care
handle it later, in their own consumer process.

```
emitting module                          stream                    consuming module
emit(Event) ─► Envelope ─► [outbox ─► publisher] ─► transport ─► modulith:events:consume
                           (table)    (process)     (redis …)     └► Dispatcher ─► handlers
```

Events go through the stream even when both modules run in the same process, so they behave the
same in every setup. The consuming module declares its handlers in its own `config/modulith.php`:

```php
return ['events' => ['listen' => ['iam.user.registered' => [RecordSignup::class]]]];
```

A handler receives the event name and the raw payload.

### The envelope

```json
{
  "id":         "0191f3c2-8a41-7c2e-9b55-3f1c7d0a4e88",
  "emitter":    "iam",
  "name":       "iam.user.registered",
  "payload":    { "id": 42 },
  "headers":    { "trace_id": "4bf92f35" },
  "emitted_at": "2026-09-30T13:22:41.512000Z",
  "recipients": [],
  "stream":     "default",
  "version":    1
}
```

`emitter` comes from the event's namespace; override `Event::emitter()` to set it yourself.

`recipients` is optional. When it's empty, every module can handle the event. When you fill it by
overriding `Event::recipients()`, only the listed modules handle it. The other consumers
acknowledge it and skip it, and the `queue` transport doesn't deliver it to them at all.

### Versioning a payload

A stream keeps events for a long time, and a consumer may be deployed after the emitter. `version`
is the version of the payload's shape. It stays at 1 as long as you only add optional fields.
Renaming a field, removing one or changing what one means raises it.

The module that owns the event declares its versions once, in its foundation, on the payload class:

```php
// foundation/Iam/Events/UserRegisteredPayload.php
final class UserRegisteredPayload implements \Modulith\Contracts\Stream\Versioned
{
    public static function version(): int { return 3; }

    public static function upcast(int $from, array $payload): array
    {
        return match ($from) {
            1 => ['id' => $payload['id'], 'full_name' => $payload['name']],   // 2 renamed name
            2 => [...$payload, 'locale' => 'en'],                             // 3 added a required locale
            default => $payload,
        };
    }
}

// foundation/FoundationServiceProvider.php
protected array $payloads = ['iam.user.registered' => UserRegisteredPayload::class];

// apps/Iam/app/Events/UserRegistered.php
public function version(): int { return UserRegisteredPayload::version(); }
```

Before a handler runs, the payload is lifted one version at a time up to the version the process
reads. A handler therefore only ever sees the current shape, including for the events written
before the change, replayed from the outbox or imported from an archive.

| The envelope's version | What happens |
|---|---|
| the version the process reads | the handler gets the payload as it is |
| older | `upcast()` runs once per missing version, then the handler |
| newer: the emitter was deployed before this consumer | `ModuleException`, no handler runs. On the `redis` transport, `on_failure: block` makes the stream wait for the consumer's deployment; with `skip` the later entries go on and this one comes back after `claim_after` |
| above 1 for an event with no entry in `$payloads` | the same `ModuleException`: an event without declared versions is read as version 1 only |

Existing databases get the `version` column of `event_publications` from a new migration: run
`php artisan migrate`.

### Context propagation

```php
// config/modulith.php
'events' => ['propagate' => ['trace_id', 'locale']],
```

These keys of Laravel's `Context` are copied into the envelope headers when the event is emitted,
and restored around each handler. RPC calls carry them too.

### Transports

| Transport | |
|---|---|
| `redis` | Redis Streams, the default. One Redis stream per configured stream (`modulith:events`), written by every module, and one consumer group per consuming module. Entries are handled in the order they were published, whichever module emitted them. With `'on_failure' => 'block'` (the default), a failing entry blocks the ones after it and is retried first. With `'skip'`, the ones after it go on, and the failed entry comes back after `claim_after`. |
| `queue` | Any Laravel queue connection (`database`, `sqs`, …), if you don't use Redis. Each envelope is copied to one queue per declared module (`modulith:events-{module}`), so every module needs a consumer. A failed envelope is retried after the ones behind it, so order isn't kept after a failure. |
| `array` | In memory, for tests. Consuming reads everything and returns. |
| `null` | Drops everything. |

A stream is an entry in `modulith.events.streams` with a driver and its options, like a queue
connection. A module can declare its own streams in its `config/modulith.php`, and an event chooses
its stream:

```php
// apps/Transactions/config/modulith.php
return ['events' => [
    'streams' => [
        'payments' => ['driver' => 'redis', 'connection' => 'payments', 'key' => 'modulith:payments'],
    ],
]];

// apps/Transactions/app/Events/PaymentCaptured.php
public function stream(): ?string
{
    return 'payments'; // null means modulith.events.stream
}
```

Order is only kept within a stream. Two events of the same module on two streams are read by two
consumers and can be handled in either order, so keep events whose order matters on the same
stream.

You can add your own driver:

```php
app(\Modulith\Services\Stream\TransportManager::class)
    ->extend('kafka', fn ($app, array $options, string $stream) => new KafkaTransport($options));
```

A transport implements `publish()`, a blocking `consume()` loop and `stop()`. If the consume
callback returns normally, the message is acknowledged; if it throws, it isn't. That is enough to
emit and consume. The other commands ask the transport for more, through these interfaces:

| Interface | Used by | Without it |
|---|---|---|
| `Contracts\Stream\TrimsStreams` | `modulith:events:trim` | the command trims nothing |
| `Contracts\Stream\TracksAcknowledgements` | `modulith:events:export --acknowledged`, and the publisher, which records the id of each entry | `--acknowledged` fails; publishing works |
| `Contracts\Stream\RedeliversEnvelopes` | the consumption guard, turned on when delivery is at-least-once | the guard stays off unless the stream has an outbox |

An adapter for Kafka, RabbitMQ or another package (on its client) implements the ones its broker
can answer, and the commands work with it unchanged.

Redis keeps every entry until every consumer group has acknowledged it. Nothing is trimmed when
writing, so a stopped consumer or a module added later doesn't miss anything. Run the trim command
on a schedule to delete what everyone has read:

```bash
php artisan modulith:events:trim [--stream=default]
```

### The outbox

On a stream with `'outbox' => true` (`MODULITH_STREAM_OUTBOX=true` for the `default` stream),
`emit()` writes a row to `event_publications` in the emitting module's database, inside the
current transaction. The data and the event are committed or rolled back together. On a stream
without an outbox, the event is published immediately. A publisher process sends the rows to the
stream:

```bash
php artisan modulith:events:publish [--module=*] [--batch=100] [--sleep=1] [--once]
```

Rows are published in `sequence` order, and a failing row stops the run. This only works with one
publisher per module, so don't run two. A row keeps the stream it was emitted on: if you remove
that stream from the config while rows are still pending, publishing for that module stops at the
first of them.

If the stream is emptied, you can rebuild it from the outbox:

```bash
php artisan modulith:events:republish [--module=*] [--since=2026-09-01] [--force]
```

To keep the table small, `export` moves published rows to a JSON-lines file in batches, and
`import` puts the rows of a file back as pending publications, in file order.

```bash
php artisan modulith:events:export storage/events.jsonl [--module=*] [--stream=default] [--until=2026-09-01] \
    [--where=name=iam.user.registered] [--where=payload.status=paid] [--acknowledged] [--batch=1000]
php artisan modulith:events:import storage/events.jsonl [--batch=1000]
```

`--where` filters on `name`, `emitter`, `stream` or a payload field. `--acknowledged` only exports
what every consumer has read, and stops at the first row that a consumer hasn't. It needs a
transport that implements `Contracts\Stream\TracksAcknowledgements`: `redis` does, `queue` can't.

### Consuming

```bash
php artisan modulith:events:consume [--module=analytics] [--stream=default]
```

The command reads one stream as the consuming module, for every emitting module on it. Run one
process per stream, like `queue:work`. On `SIGTERM` it finishes the current message and stops.

### Idempotent handlers

When delivery is at-least-once, each handler run is guarded: an `(event_id, handler)` row is
inserted into `event_consumptions` in the same transaction as the handler's writes. If the event is
delivered again, the row is already there and the handler doesn't run. If the handler throws, the
row is rolled back and the event is retried. The guard is enabled automatically with the outbox or
with a transport that implements `Contracts\Stream\RedeliversEnvelopes` (`redis` and `queue` do).
A handler that is already idempotent can implement `Contracts\Stream\Idempotent` to skip it.

## Calls between modules (RPC)

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

### One path, wherever the module runs

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

## Read-only copies (shadows)

When a module needs another module's rows locally, for example to join, filter or sort on them,
it can keep a copy in its own database, kept up to date by events. Only the source module writes
the data.

```php
// apps/Iam/app/Models/User.php, the source: the $shadowed fields are copied
final class User extends \Illuminate\Database\Eloquent\Model implements \Modulith\Contracts\Shadows\Shadowed
{
    use \Modulith\Traits\ShadowSource;

    protected $table = 'iam_users';

    protected array $shadowed = ['name'];
}

// foundation/Iam/Shadows/UserShadow.php, the shape of the copy, declared once by iam
abstract class UserShadow extends \Modulith\Models\ShadowModel
{
    public static function owner(): string { return 'iam'; }

    public static function sourceTable(): string { return 'iam_users'; }
}

// apps/Analytics/app/Models/UserShadow.php, analytics keeps a copy in the analytics_iam_users table
final class UserShadow extends \Foundation\Iam\Shadows\UserShadow {}
```

iam publishes the migration of the copy's table in `foundation/Iam/database/shadows/`, extending
`Modulith\Migrations\ShadowMigration`. `migrate` runs it in the database of every module that keeps
a copy. A copy always writes to the database of the module that keeps it.

```
iam: User saved / deleted ─► ShadowChanged event ─► analytics consumer ─► UserShadow::sync()
```

A copy rejects any write that doesn't come from `sync()`, and a deleted source row becomes a soft
delete in the copy. To fill a copy created after the source already had data:

```bash
php artisan modulith:shadows:want [--keepers=reports] [--sources=iam_users]   # on the keeping module: ask the owners to send their rows again
php artisan modulith:shadows:announce iam_users [--keepers=reports]          # on the owner: send every row again
```

`--keepers` limits the command to the modules that keep the copy, for example a new module with an
empty database. The others don't receive the rows again. Both commands address the event through
its `recipients`, so only those modules update their copy.

## Services in other languages

A service written in another language (a Node gateway, a Go worker) can take part: it emits and
consumes events on the stream, and calls the modules over RPC. The package doesn't ship a client
for it; this is what that client has to speak.

Declare it in `modulith.modules` like a module that runs elsewhere, with its `host`. A consumer
acknowledges without reading any entry whose `emitter` isn't a declared module, and a PHP module
calls it through a contract and an `RpcService` in `foundation/{Name}/`, like any other module.

### Events on Redis

| | |
|---|---|
| Stream | the stream's `key`, `modulith:events` for the `default` stream |
| Entry | `XADD {key} * envelope {json}`: one field, `envelope`, holding [the envelope](#the-envelope) as JSON |
| `emitter` | the service's name in `modulith.modules` |
| `id` | a UUID, unique per event: the consumption guard keys on it |
| Consuming | one consumer group per consuming module, named after it, created at `0`; acknowledge with `XACK` once handled |

### RPC over HTTP

```
POST {host}/{module}/rpc/{method}
Content-Type: application/json
X-Modulith-Timestamp: 1790000000            unix seconds, within rpc.signature_ttl (30 s) of the server's clock
X-Modulith-Nonce:     <a fresh UUID>        accepted once
X-Modulith-Context:   {"trace_id":"…"}      the propagated Context keys, as JSON; {} when none
X-Modulith-Signature: hex(hmac_sha256(secret, timestamp + "\n" + nonce + "\n" + path + "\n" + body + "\n" + context))

{"contract": "Foundation\\Iam\\Contracts\\IamService", "arguments": {"id": 42}}
```

`path` is `/{module}/rpc/{method}`, with its leading slash. `body` and `context` are signed as sent,
byte for byte. `arguments` are named after the method's parameters. `secret` is
`modulith.rpc.secret`. The answer is the method's return value as JSON; `null` comes back as a 404,
a bad signature as a 403.

The RPC routes are served by the same HTTP server as the modules' own routes, and accept whoever
holds the secret. Keep `/*/rpc/*` off the public entry point: a gateway forwards client requests
to the modules' routes, never to their RPC routes.

## Configuration

`php artisan vendor:publish --tag=modulith-config` publishes `config/modulith.php`, the only
configuration file. The package reads it only through the classes in `Modulith\Config\`:
`Modules`, `Streamer`, `RedisStream`, `QueueStream` and `Rpc`. What a module's folder must look
like is not configuration: see [Conventions](#conventions).

| Key | Default | |
|---|---|---|
| `modules` | `[]` | `'iam' => ['host' => …]`: every module, and the URL of the ones running elsewhere |
| `runs` | `env('MODULITH_RUNS', '*')` | modules this process runs |
| `paths.modules` | `apps` | directory of the modules |
| `paths.foundation` | `foundation` | directory of what modules share with each other |
| `namespaces.modules` | `Apps` | root namespace of the modules |
| `namespaces.foundation` | `Foundation` | root namespace of the foundation |
| `rpc.transport` | `env('MODULITH_RPC_TRANSPORT', 'http')` | transport used when a host doesn't name one |
| `rpc.transports` | `http` | named transports, each with a `driver` and its options |
| `rpc.secret` | `env('MODULITH_RPC_SECRET', env('APP_KEY'))` | signs every call; must be the same in every process |
| `rpc.signature_ttl` | `30` | how long a signature stays valid, in seconds |
| `rpc.cache` | `env('MODULITH_RPC_CACHE_STORE')` | the cache store of the RPC answers, shared by every process; `null` for the default one |
| `events.stream` | `env('MODULITH_STREAM', 'default')` | stream used when an event's `stream()` returns `null` |
| `events.streams` | `default`, on `redis` | named streams, each with a `driver` and its options; modules can add their own |
| `events.listen` | `[]` | `'event.name' => [Handler::class, ...]`, filled by the modules |
| `events.guard` | `env('MODULITH_STREAM_GUARD')` | `null` means automatic |
| `events.propagate` | `[]` | `Context` keys copied into the envelope headers |
| `status_route` | `env('MODULITH_STATUS_ROUTE')` | path of the status route, `null` for none |

Stream options, by driver:

| Driver | Option | Default | |
|---|---|---|---|
| any | `outbox` | `false` | write to `event_publications` in the current transaction; the `default` stream reads `MODULITH_STREAM_OUTBOX` |
| `redis` | `connection` | `default` | a `database.redis` connection; the `default` stream reads `MODULITH_STREAM_CONNECTION` |
| | `key` | `modulith:{stream}` | the Redis stream every module writes to; the `default` stream uses `modulith:events` |
| | `block` | `5000` | how long a read waits, in ms |
| | `count` | `50` | entries per read |
| | `claim_after` | `60000` | after how long a dead consumer's pending entries are taken back, in ms |
| | `on_failure` | `block` | `block`: a failed entry is retried before any later one; `skip`: later entries go on |
| `queue` | `connection` | `null` | a `queue.connections` entry, `null` for the default one |
| | `key` | `modulith-{stream}` | queues are `{key}-{module}` |
| | `sleep` | `1` | seconds to wait when the queue is empty |

### Source layout

```
src/
├── Providers/          ModulithServiceProvider · ModuleServiceProvider · FoundationServiceProvider
├── Http/               Controllers/{StatusController, RpcController} · Middleware/{VerifyRpcSignature, SetModuleContext}
├── Console/Commands/   Install · MakeModule · DeleteModule · ListModules · Doctor · PurgeModules · CacheModules · ClearModules · PublishEvents · RepublishEvents · ConsumeEvents
│                       TrimEvents · ExportEvents · ImportEvents · AnnounceShadows · WantShadows
├── Console/Migrations/ MigrateCommand · StatusCommand · RollbackCommand · ResetCommand · RefreshCommand
│                       FreshCommand · RunsForEachModule
├── Console/            ModuleOption · ModuleGenerators · ModuleSeedCommand
├── Config/             Modules · Streamer · RedisStream · QueueStream · Rpc
├── Contracts/
│   ├── Stream/         Bus · Transport · Handler · Versioned · Idempotent · RedeliversEnvelopes · TrimsStreams · TracksAcknowledgements
│   ├── Rpc/            RpcTransport
│   └── Shadows/        Shadowed
├── Data/               Module · Envelope
├── Models/             ShadowModel
├── Migrations/         ShadowMigration
├── Jobs/               FailedJobProvider · BatchRepository · Databases
├── Traits/             ResolvesModule · ShadowSource
├── Support/            ModuleDeferredCallbacks · ModuleConcurrencyDriver · ModuleTaskDispatcher · ModuleFactories
├── Exceptions/         ModuleException · ConfigurationException
├── Events/             Event · ShadowChanged · ShadowWanted
├── Handlers/           SyncShadows · AnnounceShadowSource
├── Testing/            Boundaries · InteractsWithModules
├── Services/
│   ├── Modules/        ModuleRegistry · DiscoveryCache · ModuleContext · ModuleMigrations · ComposerAutoload
│   ├── Stream/         Emitter · Dispatcher · EnvelopeFactory · TransportManager · PayloadVersions · Outbox/{Writer, Relay, Archive}
│   ├── Rpc/            RpcService · RpcServices · LocalServices · RpcSignature · RpcTransportManager
│   └── Shadows/        ShadowRegistry
└── Transports/
    ├── Stream/         RedisStreamTransport · QueueTransport · ArrayTransport · NullTransport
    └── Rpc/            HttpRpcTransport · LocalRpcTransport
```

## Testing

```bash
composer check   # pint + phpstan (level 6) + pest
```

## License

MIT. See [LICENSE](LICENSE).
