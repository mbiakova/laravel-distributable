# Laravel Modulith

Laravel Modulith splits a Laravel application into modules. Each module has its own database and
talks to the other modules only through events and RPC calls. You can run all the modules in one
process, or move some of them to their own process by setting `WITH_MODULES`. The code doesn't
change.

```bash
composer require mbiakova/laravel-modulith
```

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
WITH_MODULES=*

# later, the busy module runs alone and the others stay together
WITH_MODULES=transactions        # process A
WITH_MODULES=iam,analytics       # process B
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
          │ WITH_MODULES picks which modules each process boots
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
| RPC (synchronous) | reading data another module owns | the module's own class | a signed HTTP call |

Shadows, the read-only copies described below, are built on events.

When the application boots, the package:

1. finds the modules (each directory of `apps/` that contains a `modulith.php` file) and
   autoloads them under `Apps\{Module}\`;
2. registers the service provider of every module this process runs. The provider merges the
   module's config files, loads its routes, translations and commands, and binds the contracts
   the module answers itself;
3. registers the foundation's service provider, which binds every other RPC contract to the
   `RpcService` that calls its module over the network.

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
| `Contracts\Modules\Source`, the module list | `ManifestSource` | `modulith.source` |
| `Contracts\Stream\Transport`, the event stream | `redis`, `queue`, `array`, `null` | `TransportManager::extend()` |
| `Contracts\Rpc\RpcTransport`, calls between modules | `HttpRpcTransport` | `RpcTransportManager::extend()` |

The package defines the envelope format, which is why transports can be swapped.

### Compared to other packages

| | nwidart/laravel-modules | Spring Modulith | laravel-modulith |
|---|---|---|---|
| Goal | organise code in modules | module boundaries and events | organise code in modules and deploy them separately |
| Data | one shared database | one datasource | one database per module |
| Between modules | direct calls | events and outbox | event stream with an ordered outbox, and RPC |
| Moving a module to its own service | rewrite | new application | `WITH_MODULES` |

### Out of scope

- Turning modules on or off at runtime. Which modules run is decided at boot.
- A `composer.json` per module while modules are deployed together: there is one `vendor/` and
  one lockfile.
- Orchestration (Kubernetes, proxies). That belongs to your infrastructure.
- Enforcing a code style.

## Quick start

`app/` is still your Laravel application. Each module lives in `apps/`.

```
apps/Iam/
├── modulith.php                      marks the directory as a module
├── app/                              Apps\Iam\, laid out like a Laravel app
│   ├── Providers/IamServiceProvider.php
│   ├── Models/User.php
│   └── Events/UserRegistered.php
├── config/
│   ├── database.php                  its connections; if the file exists, the module has a database
│   └── streamer.php                  the events it listens to
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
// apps/Iam/modulith.php
return [];

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

// apps/Analytics/config/streamer.php
return ['listen' => ['iam.user.registered' => [RecordSignup::class]]];
```

```bash
php artisan modulith:events:consume --module=analytics
```

## Modules

### Declaring a module

A directory inside the modules directory is a module if it contains a `modulith.php` file. The
file just returns `[]`. Everything else is derived from the directory name. You can change the
modules directory and namespace:

```php
// config/modulith.php
'modules_path'      => 'apps',   // e.g. 'modules'
'modules_namespace' => 'Apps',   // e.g. 'Modules'
```

| | Module `Iam`, with the defaults |
|---|---|
| name | `iam`, the directory name in snake_case (`^[a-z][a-z0-9_]*$`) |
| namespace | `Apps\Iam`, autoloaded from `apps/Iam/app`, no `composer.json` entry needed |
| provider | `Apps\Iam\Providers\IamServiceProvider` |
| database | yes, because `apps/Iam/config/database.php` exists |

```bash
php artisan modulith:make-module point_of_sale [--database]   # creates apps/PointOfSale and foundation/PointOfSale
php artisan modulith:list                                     # lists the modules, where they run, their database and host
php artisan modulith:doctor                                   # checks that the modules can run, see "Checking the application"
```

### Which modules a process runs

```dotenv
WITH_MODULES=*                       # every module (the default)
WITH_MODULES=transactions,analytics  # only these
```

`Modulith\Services\Modules\ModuleRegistry` gives you both lists: `all()` returns every declared
module and `local()` the ones this process runs. `get($name)` throws on an unknown name, and
`forClass($class)` returns the module a class belongs to, based on its namespace.

Modules are discovered by scanning the directory tree. In production, `php artisan optimize` runs
`modulith:cache`, which writes the modules, their copies and the shadow sources to
`bootstrap/cache/modulith.php`, and the application reads that file instead. `optimize:clear` (or
`modulith:clear`) deletes it.

With `MODULITH_STATUS_ROUTE=/`, the process returns the modules it runs:

```json
{ "message": "Hello from laravel-modulith", "modules": ["transactions", "analytics"], "status": "ok" }
```

### The module service provider

A provider that extends `Modulith\Providers\ModuleServiceProvider` loads the following from the
module directory:

| Source | What happens |
|---|---|
| `config/*.php` | Merged into the root config file with the same name. Nested keys are merged; list items are added once. |
| `routes/{name}.php` | Loaded under the `{module}/{name}` prefix, in the `{name}` middleware group if the application defines one. |
| `lang/` | Loaded under the module name: `__('iam::messages.hello')`. |
| Artisan commands | Every `Illuminate\Console\Command` in `app/` is registered (console only). |

### Declaring modules another way

The default `Modulith\Contracts\Modules\Source` looks for `modulith.php` files. To declare modules
another way, set `modulith.source` to your own implementation that returns a
`list<Modulith\Data\Module>`.

### The foundation

A module never uses another module's classes, because that import would break as soon as the
modules are deployed separately. What a module shares with the others (its RPC contracts and
services, the shape of its copies, its event names) goes in `foundation/{Module}/`, autoloaded as
`Foundation\{Module}\` (`modulith.foundation_path`, `modulith.foundation_namespace`). Any module can
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
| a module whose service provider class doesn't exist | `[gateway] provider Apps\Gateway\Providers\GatewayServiceProvider does not exist.` |
| a local module with a database but no declared connection | `[iam] connection [iam_owner] is not declared.` |
| a module running elsewhere that serves a contract, with no `rpc.hosts` entry | `[iam] runs elsewhere and serves Foundation\Iam\Contracts\IamService, but rpc.hosts has no entry for it.` |
| RPC contracts declared while the secret is empty | `Modules serve RPC contracts but rpc.secret is empty: set MODULITH_RPC_SECRET or APP_KEY.` |
| a module using another module's classes, or the foundation using a module | `Boundary crossed: apps/Analytics/app/Models/Report.php: Apps\Iam\Models\User` |

It lists every problem and exits with a non-zero code if there is at least one.

`Modulith\Testing\Boundaries` is the architecture check on its own, for your test suite. It
doesn't depend on the configuration: it reads the PHP files of every module and of the foundation.

```php
// tests/Architecture/BoundariesTest.php
it('keeps the modules apart', function () {
    expect(app(\Modulith\Testing\Boundaries::class)->violations())->toBe([]);
});
```

## A database per module

### Connections

A module with a database declares two connections in its `config/database.php`:

| Connection | Role |
|---|---|
| `{module}` | Used at runtime to read and write rows. |
| `{module}_owner` | Owns the tables and can create and alter them. Only the `migrate` commands use it. |

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
| job | `Queue::before` | the module of the job class |
| command | `CommandStarting` | the module of the command class |
| event handler | `Dispatcher`, around each handler | the module of the handler class |

Outside a module, the application's default connection is used.
`Services\Modules\ModuleContext::current()` returns the current module (`Data\Module`) or null,
and `within($module, $callback)` runs a callback in a module's context.

In tests, use `Modulith\Testing\InteractsWithModules`:

```php
$user = $this->inModule('iam', fn () => User::query()->create(['name' => 'ada']));   // iam database
```

### Migrations

The package extends Laravel's migrate commands so they run once per database. This also applies
to `RefreshDatabase` in your tests.

```bash
php artisan migrate | migrate:status | migrate:rollback | migrate:reset | migrate:refresh | migrate:fresh  [--module=*]
```

| Database | Migrations |
|---|---|
| the application's default database | `database/migrations`, plus the migrations of modules that have no database |
| each local module's database, on `{module}_owner` | the package's tables (`event_publications`, `event_consumptions`), `database/migrations`, the module's `database/migrations` and the shadow migrations of the copies it keeps |

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
same in every setup. The consuming module declares its handlers in its `config/streamer.php`:

```php
return ['listen' => ['iam.user.registered' => [RecordSignup::class]]];
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
  "recipients": []
}
```

`emitter` comes from the event's namespace; override `Event::emitter()` to set it yourself. Only
add fields to a payload. If you need a breaking change, use a new event name.

`recipients` is optional. When it's empty, every module can handle the event. When you fill it by
overriding `Event::recipients()`, only the listed modules handle it. The other consumers
acknowledge it and skip it, and the `queue` transport doesn't deliver it to them at all.

### Context propagation

```php
// config/streamer.php
'propagate' => ['trace_id', 'locale'],
```

These keys of Laravel's `Context` are copied into the envelope headers when the event is emitted,
and restored around each handler. RPC calls carry them too.

### Transports

| Transport | |
|---|---|
| `redis` | Redis Streams, the default. One stream per emitting module (`modulith:events:{module}`) and one consumer group per consuming module. Entries are handled in order: a failing entry blocks the ones after it and is retried first. |
| `queue` | Any Laravel queue connection (`database`, `sqs`, …), if you don't use Redis. Each envelope is copied to one queue per module with a database (`modulith-events-{module}`). A failed envelope is retried after the ones behind it, so order isn't kept after a failure. |
| `array` | In memory, for tests. Consuming reads everything and returns. |
| `null` | Drops everything. |

A stream is an entry in `streamer.streams` with a driver and its options, like a queue
connection. A module can declare its own streams in its `config/streamer.php`, and an event chooses
its stream:

```php
// apps/Transactions/config/streamer.php
return [
    'streams' => [
        'payments' => ['driver' => 'redis', 'connection' => 'payments'],
    ],
];

// apps/Transactions/app/Events/PaymentCaptured.php
public function stream(): ?string
{
    return 'payments'; // null means streamer.default
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
callback returns normally, the message is acknowledged; if it throws, it isn't.

Redis keeps every entry until every consumer group has acknowledged it. Nothing is trimmed when
writing, so a stopped consumer or a module added later doesn't miss anything. Run the trim command
on a schedule to delete what everyone has read:

```bash
php artisan modulith:events:trim [--module=*] [--stream=default]
```

### The outbox

On a stream with `'outbox' => true` (`MODULITH_STREAMER_OUTBOX=true` for the `default` stream),
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
foundation/Iam/Services/IamRpcService.php    extends RpcService, calls iam over the network
foundation/FoundationServiceProvider.php     binds IamService to IamRpcService, unless iam runs here
apps/Iam/app/Services/IamService.php         implements the contract with iam's own data
apps/Iam/app/Providers/IamServiceProvider    binds IamService to it when iam runs here
apps/Iam/routes/rpc.php                      answers the calls, under /iam/rpc/…
```

The foundation has one service provider, which the package registers for you. It lists the
contracts and their `RpcService`, and binds a contract only if no module has bound it already.
A module that runs in this process binds its own implementation in its provider, so that one is
used.

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
        return $this->remember("iam:user:{$id}", 3600, fn () => $this->call('users', 'find', ['id' => $id]));
    }
}

// apps/Iam/app/Services/IamService.php
final class IamService implements \Foundation\Iam\Contracts\IamService
{
    public function findUser(int $id): ?array { /* query iam's database */ }
}

// apps/Iam/routes/rpc.php
Route::prefix('v1')->group(function () {
    Route::post('users/find', fn (Request $r, IamService $iam) => $iam->findUser($r->integer('id')) ?? abort(404));
});
```

Other modules type-hint `Foundation\Iam\Contracts\IamService` and don't need to know where iam
runs. A call to another process is a `POST {host}/iam/rpc/v1/users/find`, signed with an HMAC of
the timestamp, a nonce, the path, the body and the propagated context. The `rpc` middleware group
rejects requests that are unsigned, too old, modified or replayed (each nonce is accepted once,
using the cache). A 404 response returns `null`.

`RpcService` can cache answers: `remember($key, $ttl, $fetch)`, `rememberUntil($key, $fetch,
$ttlOf)` when the answer contains its own lifetime (for example a token cached until it expires),
and `forget($key)`.

```php
// config/rpc.php
'hosts'  => ['iam' => 'https://iam.internal'],
'secret' => env('MODULITH_RPC_SECRET', env('APP_KEY')),
```

The caller and the called process must use the same secret. It defaults to `APP_KEY`, which works
when all processes are deployed with the same `.env`. If a module is deployed with its own
`APP_KEY`, set the same `MODULITH_RPC_SECRET` on both sides, otherwise the calls are rejected with
a 403.

Calls use the `http` transport unless the host names another one. To add a driver:

```php
// config/rpc.php
'transports' => ['http' => ['driver' => 'http'], 'grpc' => ['driver' => 'grpc', 'port' => 50051]],
'hosts'      => ['iam' => ['url' => 'grpc://iam.internal', 'transport' => 'grpc']],

app(\Modulith\Services\Rpc\RpcTransportManager::class)
    ->extend('grpc', fn ($app, array $config, string $name) => new GrpcRpcTransport($config));
```

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

## Configuration

`php artisan vendor:publish --tag=modulith-config` publishes three files. The package reads them
only through the classes in `Modulith\Config\`: `Modules`, `Streamer`, `RedisStream`,
`QueueStream` and `Rpc`.

`config/modulith.php`

| Key | Default | |
|---|---|---|
| `source` | `ManifestSource::class` | provides the module list |
| `with` | `env('WITH_MODULES', '*')` | modules this process runs |
| `modules_path` | `apps` | directory scanned for modules |
| `modules_namespace` | `Apps` | root namespace of the modules |
| `foundation_path` | `foundation` | directory of what modules share with each other |
| `foundation_namespace` | `Foundation` | its root namespace |
| `status_route` | `env('MODULITH_STATUS_ROUTE')` | path of the status route, `null` for none |

`config/streamer.php`

| Key | Default | |
|---|---|---|
| `default` | `env('MODULITH_STREAMER_STREAM', 'default')` | stream used when an event's `stream()` returns `null` |
| `streams` | `default`, on `redis` | named streams, each with a `driver` and its options; modules can add their own |
| `guard` | `env('MODULITH_STREAMER_GUARD')` | `null` means automatic |
| `listen` | `[]` | `'event.name' => [Handler::class, ...]`, filled by the modules |
| `propagate` | `[]` | `Context` keys copied into the envelope headers |

Stream options, by driver:

| Driver | Option | Default | |
|---|---|---|---|
| any | `outbox` | `false` | write to `event_publications` in the current transaction; the `default` stream reads `MODULITH_STREAMER_OUTBOX` |
| `redis` | `connection` | `default` | a `database.redis` connection |
| | `prefix` | `modulith:{stream}:` | keys are `{prefix}{module}`; the `default` stream uses `modulith:events:` |
| | `block` | `5000` | how long a read waits, in ms |
| | `count` | `50` | entries per read |
| | `claim_after` | `60000` | after how long a dead consumer's pending entries are taken back, in ms |
| `queue` | `connection` | `null` | a `queue.connections` entry, `null` for the default one |
| | `prefix` | `modulith-{stream}-` | queues are `{prefix}{module}` |
| | `sleep` | `1` | seconds to wait when the queue is empty |

`config/rpc.php`

| Key | Default | |
|---|---|---|
| `services` | `[]` | contract → `module` and `rpc` service, filled at boot from the `FoundationServiceProvider` |
| `default` | `env('MODULITH_RPC_TRANSPORT', 'http')` | transport used when a host doesn't name one |
| `transports` | `http` | named transports, each with a `driver` and its options |
| `hosts` | `[]` | `'iam' => 'https://iam.internal'`, or `['url' => …, 'transport' => 'grpc']` |
| `secret` | `env('MODULITH_RPC_SECRET', env('APP_KEY'))` | signs every call; must be the same in every process |
| `signature_ttl` | `30` | how long a signature stays valid, in seconds |

### Source layout

```
src/
├── Providers/          ModulithServiceProvider · ModuleServiceProvider · FoundationServiceProvider
├── Http/               Controllers/StatusController · Middleware/{VerifyRpcSignature, SetModuleContext}
├── Console/Commands/   MakeModule · ListModules · Doctor · CacheModules · ClearModules · PublishEvents · RepublishEvents · ConsumeEvents
│                       TrimEvents · ExportEvents · ImportEvents · AnnounceShadows · WantShadows
├── Console/Migrations/ MigrateCommand · StatusCommand · RollbackCommand · ResetCommand · RefreshCommand
│                       FreshCommand · RunsForEachModule
├── Config/             Modules · Streamer · RedisStream · QueueStream · Rpc
├── Contracts/
│   ├── Modules/        Source
│   ├── Stream/         Bus · Transport · Handler · Idempotent · RedeliversEnvelopes · TrimsStreams · TracksAcknowledgements
│   ├── Rpc/            RpcTransport
│   └── Shadows/        Shadowed
├── Data/               Module · Envelope
├── Models/             ShadowModel
├── Migrations/         ShadowMigration
├── Jobs/               FailedJobProvider · BatchRepository · Databases
├── Traits/             ResolvesModule · ShadowSource
├── Exceptions/         ModuleException · ConfigurationException
├── Events/             Event · ShadowChanged · ShadowWanted
├── Handlers/           SyncShadows · AnnounceShadowSource
├── Testing/            Boundaries · InteractsWithModules
├── Services/
│   ├── Modules/        ModuleRegistry · ManifestSource · CachedSource · DiscoveryCache · ModuleContext · ModuleMigrations
│   ├── Stream/         Emitter · Dispatcher · EnvelopeFactory · TransportManager · Outbox/{Writer, Relay, Archive}
│   ├── Rpc/            RpcService · RpcSignature · RpcTransportManager
│   └── Shadows/        ShadowRegistry
└── Transports/
    ├── Stream/         RedisStreamTransport · QueueTransport · ArrayTransport · NullTransport
    └── Rpc/            HttpRpcTransport
```

## Testing

```bash
composer check   # pint + phpstan (level 6) + pest
```

## License

MIT. See [LICENSE](LICENSE).
