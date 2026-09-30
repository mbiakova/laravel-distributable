# Laravel Modulith

Design your Laravel application as modules built like independent services — each one owns its
database, and talks to the others only through events and signed calls — in a single codebase.
Deploy them grouped in **one process** while the load is low; give any module **its own
service** when it needs one — by changing an environment variable, not the code.

```bash
composer require mbiakova/laravel-modulith
```

Requires PHP 8.4+ and Laravel 12 or 13. No other runtime dependency.

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

Microservices give each part of a system its own data, its own contracts and its own
deployment. They also charge for it from day one: one server, one database, one pipeline and
one bill per service — long before the load justifies any of it.

Laravel Modulith lets you **design every module as an independent service** — its own database,
no shared tables, no direct calls — while keeping one codebase. How modules are *deployed* is a
separate decision, made in the environment, and you can change it at any time:

```dotenv
# early on: every module in one process, one server, one bill
WITH_MODULES=*

# later: the busy module runs alone, the quiet ones stay grouped
WITH_MODULES=transactions        # service A — scaled on its own
WITH_MODULES=iam,analytics       # service B — still sharing one process
```

| | |
|---|---|
| **Cost follows load** | Group modules into as few services as the traffic allows; split one out only when it earns its own servers. |
| **Extraction is a deployment, not a rewrite** | A module already owns its data and speaks only through events and contracts. Moving it into its own service changes an environment variable. |
| **Same semantics in every topology** | Events always travel on a stream; the outbox and the consumption guard behave identically whether two modules share a process or not. |
| **Microservice discipline, monolith ergonomics** | One repository, one language, one test suite, one `composer install` — with boundaries the code enforces instead of a wiki. |

When modules share a database and call each other directly, splitting one out is a migration
project. Here it is a line in `.env`.

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

Modules talk in exactly **two** ways, and neither is a direct call:

| Mode | For | Same process | Different processes |
|---|---|---|---|
| **Event stream** — asynchronous | announcing a fact; nobody waits for an answer | on the stream | on the stream |
| **RPC** — synchronous | a read another module answers now | contract bound to the local class | contract bound to a signed HTTP client |

Everything else is built on these two. A **shadow** — a read-only copy of another module's rows
kept in your database — is fed by events; nothing else crosses a module.

At boot, the package:

1. discovers the modules (a directory of `apps/` with a `modulith.php` marker) and autoloads
   each one under `Apps\{Module}\`;
2. binds every RPC contract, for all modules, to its local or remote implementation;
3. registers the provider of each module this process runs — which merges its config fragments
   (including its database connections and its event handlers), loads its routes, migrations,
   translations and commands.

A module this process does not run has no config, no routes, no handlers here — only a remote
binding for its contracts.

At run time, each request, job and command runs in the context of the module it belongs to, whose
database becomes the default one ([details](#models-and-transactions)).

## Design

### Mechanism, not style

The package ships only what "a deployable module" cannot mean without. How to write an
application — response envelopes, DTOs, repositories, exception philosophy, authentication — is
left to you.

The rule applied to every piece: **it belongs here if violating it breaks a guarantee of the
package.** Module code that runs outside its module's context writes to another database, so the
context is here. A module without a repository breaks nothing, so repositories are not.

### Every seam stays open

| Seam | Default | Replace with |
|---|---|---|
| `Contracts\Modules\Source` — the module list | `ManifestSource` | `modulith.source` |
| `Contracts\Stream\Transport` — the event stream | `redis`, `queue`, `array`, `null` | `TransportManager::extend()` |
| `Contracts\Rpc\RpcTransport` — calls between modules | `Transports\Rpc\HttpRpcTransport` | `Services\Rpc\RpcTransportManager::extend()` |

The package owns the **envelope format**: that is what keeps transports interchangeable.

### How it compares

| | nwidart/laravel-modules | Spring Modulith | **laravel-modulith** |
|---|---|---|---|
| Purpose | organise the code in modules | boundaries + events | organise the code in modules **and deploy them apart** |
| Data | one shared database | one datasource | **one database per module** |
| Between modules | direct calls | events + outbox | **event stream (ordered outbox) + RPC — nothing else** |
| Path to services | rewrite | new application | **`WITH_MODULES`** |

### Non-goals

- Toggling modules at runtime — the topology is fixed at boot; it is a deployment decision.
- A `composer.json` per module while modules deploy together: one `vendor/`, one lockfile.
- Orchestration (Kubernetes, proxies) — that is the application's infrastructure.
- Imposing a code style.

## Quick start

`app/` stays your Laravel application; each module is a deployable app of its own, in `apps/`.

```
apps/Iam/
├── modulith.php                      marker: this directory is a module
├── app/                              Apps\Iam\ — laid out like a Laravel app, so an extracted module already is one
│   ├── Providers/IamServiceProvider.php
│   ├── Models/User.php
│   └── Events/UserRegistered.php
├── config/
│   ├── database.php                  its connections — present = the module has a database
│   └── streamer.php                  the events it listens to
├── database/migrations/
└── routes/api.php                    served under /iam/api/…

foundation/Iam/                       Foundation\Iam\ — what iam publishes for the other modules
├── Contracts/IamService.php
├── Services/IamRpcService.php        how another process calls iam
├── Shadows/UserShadow.php
└── rpc.php                           IamService::class => IamRpcService::class
```

```php
// apps/Iam/modulith.php
return [];

// apps/Iam/app/Providers/IamServiceProvider.php
final class IamServiceProvider extends \Modulith\Providers\ModuleServiceProvider {}

// apps/Iam/app/Models/User.php — plain Eloquent: it runs on iam's database because iam's code does
final class User extends \Illuminate\Database\Eloquent\Model {}
```

```php
// apps/Iam/config/database.php — merged into config/database.php when iam boots
return ['connections' => [
    'iam'       => ['driver' => 'pgsql', 'database' => 'iam', 'username' => 'iam_app'],   // reads and writes
    'iam_owner' => ['driver' => 'pgsql', 'database' => 'iam', 'username' => 'iam_owner'], // creates and alters tables
]];
```

```bash
php artisan migrate   # the application's database, then each module's
```

Emit an event from one module, handle it in another:

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

A directory of the modules directory is a module **when it carries a `modulith.php` file**. The
file is a marker and returns `[]`; everything else follows from the directory name. The modules
directory and their root namespace are yours to choose:

```php
// config/modulith.php
'modules_path'      => 'apps',   // e.g. 'modules'
'modules_namespace' => 'Apps',   // e.g. 'Modules'
```

| | Module `Iam`, with the defaults |
|---|---|
| name | `iam` — the directory in snake_case, `^[a-z][a-z0-9_]*$` |
| namespace | `Apps\Iam`, autoloaded from `apps/Iam/app` — no entry in `composer.json` |
| provider | `Apps\Iam\Providers\IamServiceProvider` |
| database | yes, because `apps/Iam/config/database.php` exists |

```bash
php artisan modulith:make-module point_of_sale [--database]   # apps/PointOfSale + foundation/PointOfSale
php artisan modulith:list                                     # every module, where it runs, its database, its host
php artisan modulith:doctor                                   # providers, connections, remote hosts, boundaries — fails on the first problem found
```

### Which modules this process runs

```dotenv
WITH_MODULES=*                       # every module (the default)
WITH_MODULES=transactions,analytics  # only these
```

`Modulith\Services\Modules\ModuleRegistry` holds both views: `all()` — every declared module — and
`local()` — the ones this process boots. `get($name)` fails loudly on an unknown name;
`forClass($class)` resolves the module owning a class from its namespace.

Discovery scans the tree. On deploy, `php artisan optimize` runs `modulith:cache`, which writes
the modules, their copies and the shadow sources to `bootstrap/cache/modulith.php`; the process
then reads that file instead. `optimize:clear` (or `modulith:clear`) removes it.

With `MODULITH_STATUS_ROUTE=/`, the process answers with the modules it boots:

```json
{ "message": "Hello from laravel-modulith", "modules": ["transactions", "analytics"], "status": "ok" }
```

### The module provider

Extending `Modulith\Providers\ModuleServiceProvider` wires, from the module directory:

| Source | Effect |
|---|---|
| `config/*.php` | Deep-merged into the root config of the same name. Associative keys recurse; list items are appended **once**. |
| `routes/{name}.php` | Loaded under the `{module}/{name}` prefix, inside the `{name}` middleware group when the application defines one. |
| `lang/` | Loaded under the module name: `__('iam::messages.hello')`. |
| Artisan commands | Every `Illuminate\Console\Command` found in `app/` is registered (console only). |

### Declaring modules another way

The marker scan is the default `Modulith\Contracts\Modules\Source`. Point `modulith.source` at your own
implementation returning a `list<Modulith\Data\Module>`.

### The foundation

A module never names another module's classes: that import is the dependency a split would
break. What a module publishes for the others — its RPC contracts, the shapes of its copies, its
event names — lives in `foundation/{Module}/`, autoloaded as `Foundation\{Module}\`
(`modulith.foundation_path`, `modulith.foundation_namespace`). Every module may import the
foundation; the foundation imports no module.

```
apps/Analytics ──► foundation/Iam ◄── apps/Iam
       └──────── never ──────────────┘
```

`Modulith\Testing\Boundaries` reads every module's and the foundation's PHP files and lists
each name crossing that line. Keep it empty from your suite:

```php
// tests/Architecture/BoundariesTest.php
it('keeps the modules apart', function () {
    expect(app(\Modulith\Testing\Boundaries::class)->violations())->toBe([]);
});
```

## A database per module

### Connections

A module with a database declares two connections in its own `config/database.php`:

| Connection | Role |
|---|---|
| `{module}` | The runtime connection: reads and writes rows, nothing else. |
| `{module}_owner` | Owns the tables: creates and alters them. Used only by the `migrate` commands. |

What sits behind them is a deployment variable; the code is identical in every case — N
databases on one server, N schemas of one database, N servers, N sqlite files in tests. Several
modules may even point at the same database: the outbox filters its rows by emitter.

The boundary is held by the code, not by the server. **No foreign key crosses a module**:
reference another module's rows by bare id, or keep a [shadow](#read-only-copies-shadows).

### Models and transactions

Models are plain Eloquent — `User extends Authenticatable` included. Every request to a module
route, every job, command and event handler whose class lives in a module runs in that
**module's context**: its connection becomes the default one, so models, `DB::`,
`DB::transaction()` and `Schema::` land in its database with nothing to write. Modules sharing
one database name their tables apart (`protected $table = 'iam_users'`).

```php
// apps/Iam/app/Actions/RegisterUser.php — called from an iam route, job or command
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
| job | `Queue::before` | the module owning the job class |
| command | `CommandStarting` | the module owning the command class |
| event handler | `Dispatcher`, around each handler | the module owning the handler class |

Outside any module, the application's own default connection is put back.
`Services\Modules\ModuleContext::current()` returns the running module (`Data\Module`), or null;
`within($module, $callback)` runs a callback in a module's context.

In tests, `Modulith\Testing\InteractsWithModules` gives the test case the same thing:

```php
$user = $this->inModule('iam', fn () => User::query()->create(['name' => 'ada']));   // iam database
```

### Migrations

Laravel's own commands, run once per database — `RefreshDatabase` in your tests included:

```bash
php artisan migrate | migrate:status | migrate:rollback | migrate:reset | migrate:refresh | migrate:fresh  [--module=*]
```

| Database | Migrations |
|---|---|
| the application's default | its `database/migrations`, and those of modules with no database of their own |
| each local module's, on `{module}_owner` | the package's tables (`event_publications`, `event_consumptions`), the application's `database/migrations`, the module's `database/migrations`, the shadow migrations of the copies it keeps |

`--module` limits a run to those modules and skips the application's database. An explicit
`--database` or `--path` is the plain Laravel command. Each database keeps its own history.

### Queued jobs

Laravel's `failed_jobs` and `job_batches` follow the same rule: a failed job or a batch is stored
in the database of the **module owning the job class**, and `queue:failed`, `queue:retry` or
`Bus::findBatch()` read across the databases of the modules this process runs. Nothing to
configure — it applies when Laravel stores them in a database (`database-uuids` failer, database
batches), and the tables come from the application's `database/migrations`, run in every module
database.

## Events

### The pipeline

Events are the **asynchronous** channel: a module announces a fact on a stream, and the modules
interested in it react later, in their own consumer process.

```
emitting module                          stream                    consuming module
emit(Event) ─► Envelope ─► [outbox ─► publisher] ─► transport ─► modulith:events:consume
                           (table)    (process)     (redis …)     └► Dispatcher ─► handlers
```

Events always travel on the stream, even when both modules run in the same process: the
semantics are then the same in every topology. Handlers are declared by the **consuming**
module, in its `config/streamer.php`:

```php
return ['listen' => ['iam.user.registered' => [RecordSignup::class]]];
```

A handler receives the event name and the raw payload — what any consumer has, local or not.

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

`emitter` is resolved from the event's namespace (override `Event::emitter()` otherwise).
Payloads evolve additively; a breaking change is a **new event name**.

`recipients` is optional. Empty, every module may handle the event. Filled — override
`Event::recipients()` — only the modules it names handle it; every other consumer acknowledges it
and moves on, and the `queue` transport does not even deliver it to them.

### Context propagation

```php
// config/streamer.php
'propagate' => ['trace_id', 'locale'],
```

Those keys of Laravel's `Context` are copied into the envelope headers on emit, and restored
around each handler on the consuming side. RPC calls carry them too.

### Transports

| Transport | |
|---|---|
| `redis` | Redis Streams: one stream per emitting module (`modulith:events:{module}`), one consumer group per consuming module. In order: a failing entry blocks the ones behind it and is retried first. The default. |
| `queue` | Any Laravel queue connection — `database`, `sqs`, … — for a stack without Redis. Each envelope is copied to one queue per module with a database (`modulith-events-{module}`). A failed envelope is retried after the ones behind it: **no order across a failure**. |
| `array` | In memory, for tests: consuming drains it and returns. |
| `null` | Drops everything. |

A stream is a name in `streamer.streams` with a driver and its options, like a queue
connection. A module declares its own in its `config/streamer.php`, and an event picks one:

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
    return 'payments'; // null: streamer.default
}
```

Order holds **within a stream only**: two events of one module on two streams are read by two
consumers, and may be applied in either order. Keep on one stream the events whose order matters.

Register your own driver — no fork, no pull request:

```php
app(\Modulith\Services\Stream\TransportManager::class)
    ->extend('kafka', fn ($app, array $options, string $stream) => new KafkaTransport($options));
```

A transport implements `publish()`, a blocking `consume()` loop and `stop()`. Returning normally
from the consume callback acknowledges the message; throwing does not.

Redis keeps every entry until **every consumer group has acknowledged it** — nothing is trimmed
on write, so a stopped consumer or a module plugged in later loses nothing. Drop what everyone
has read on a schedule:

```bash
php artisan modulith:events:trim [--module=*] [--stream=default]
```

### The outbox

On a stream with `'outbox' => true` (`MODULITH_STREAMER_OUTBOX=true` for `default`), `emit()`
writes a row to `event_publications` **in the emitting module's database**, inside the business
transaction: the fact and its announcement commit or roll back together. A stream without it
publishes straight away, with no table and no publisher. A single publisher process puts the
rows on the wire:

```bash
php artisan modulith:events:publish [--module=*] [--batch=100] [--sleep=1] [--once]
```

**Order is a guarantee.** Rows are published in `sequence` order, and a failing row stops the
pass. That holds with **one publisher per module**; do not run two. A row keeps the stream it
was emitted on: remove that stream from the config while rows are pending, and the module's
publication stops on the first of them.

The outbox is also the archive: if the broker is emptied, rebuild the stream from it.

```bash
php artisan modulith:events:republish [--module=*] [--since=2026-09-01] [--force]
```

Published rows can leave the table without being lost: `export` moves them to a JSON-lines file
in batches, `import` puts a file's rows back as pending publications, in file order.

```bash
php artisan modulith:events:export storage/events.jsonl [--module=*] [--stream=default] [--until=2026-09-01] \
    [--where=name=iam.user.registered] [--where=payload.status=paid] [--acknowledged] [--batch=1000]
php artisan modulith:events:import storage/events.jsonl [--batch=1000]
```

`--where` filters on `name`, `emitter`, `stream` or a payload field. `--acknowledged` keeps only
what **every consumer has read**, and stops at the first row one has not: it needs a transport
implementing `Contracts\Stream\TracksAcknowledgements` (`redis` does; `queue` cannot know).

### Consuming

```bash
php artisan modulith:events:consume [--module=analytics] [--stream=default]
```

Reads one stream, every emitting module on it, as the consuming module — one process per
stream, like `queue:work`. Stops after the current message on `SIGTERM`.

### Idempotent handlers

With at-least-once delivery, every handler run is guarded: an `(event_id, handler)` mark is
inserted into `event_consumptions` **in the same transaction** as the handler's writes. A
redelivery finds the mark and does nothing; a handler that throws rolls its mark back and is
replayed. The guard turns itself on with the outbox or a transport implementing
`Contracts\Stream\RedeliversEnvelopes` (`redis` and `queue` do). A handler that is idempotent by
nature implements `Contracts\Stream\Idempotent` and skips it.

## Calls between modules (RPC)

For a synchronous read another module answers. The owning module publishes, in its foundation,
the contract and the `RpcService` that calls it over the network — every caller has them, split
or not — and implements the contract in its own tree:

```
foundation/Iam/Contracts/IamService.php      the contract
foundation/Iam/Services/IamRpcService.php    extends RpcService — bound when iam runs elsewhere
foundation/Iam/rpc.php                        IamService::class => IamRpcService::class
apps/Iam/app/Services/IamService.php         implements it — bound when iam runs here
apps/Iam/routes/rpc.php                      the answering side, under /iam/rpc/…
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

// foundation/Iam/rpc.php
return [IamService::class => IamRpcService::class];

// apps/Iam/app/Services/IamService.php — {Module}\Services\{Contract}, found by convention
final class IamService implements \Foundation\Iam\Contracts\IamService
{
    public function findUser(int $id): ?array { /* query iam's own database */ }
}

// apps/Iam/routes/rpc.php
Route::prefix('v1')->group(function () {
    Route::post('users/find', fn (Request $r, IamService $iam) => $iam->findUser($r->integer('id')) ?? abort(404));
});
```

Other modules type-hint `Foundation\Iam\Contracts\IamService` and never know where iam runs. A remote call is a
`POST {host}/iam/rpc/v1/users/find`, signed with an HMAC over the timestamp, a nonce, the path,
the body and the propagated context; the `rpc` middleware group rejects anything unsigned,
stale, altered or replayed (a nonce is accepted once, through the cache). A 404 answers `null`.

`RpcService` caches answers: `remember($key, $ttl, $fetch)`, `rememberUntil($key, $fetch,
$ttlOf)` when the answer carries its own lifetime (a token cached until it expires), and
`forget($key)`.

```php
// config/rpc.php
'hosts'  => ['iam' => 'https://iam.internal'],
'secret' => env('MODULITH_RPC_SECRET'),
```

Calls travel on the `http` transport unless a host names another. Register your own driver:

```php
// config/rpc.php
'transports' => ['http' => ['driver' => 'http'], 'grpc' => ['driver' => 'grpc', 'port' => 50051]],
'hosts'      => ['iam' => ['url' => 'grpc://iam.internal', 'transport' => 'grpc']],

app(\Modulith\Services\Rpc\RpcTransportManager::class)
    ->extend('grpc', fn ($app, array $config, string $name) => new GrpcRpcTransport($config));
```

## Read-only copies (shadows)

When a module needs another module's rows at local speed — to join, filter, sort — it keeps a
**copy** in its own database, fed by events. The source module stays the only writer.

```php
// apps/Iam/app/Models/User.php — the source: these fields travel
final class User extends \Illuminate\Database\Eloquent\Model implements \Modulith\Contracts\Shadows\Shadowed
{
    use \Modulith\Traits\ShadowSource;

    protected $table = 'iam_users';

    protected array $shadowed = ['name'];
}

// foundation/Iam/Shadows/UserShadow.php — the shape of a copy, declared once by iam
abstract class UserShadow extends \Modulith\Models\ShadowModel
{
    public static function owner(): string { return 'iam'; }

    public static function sourceTable(): string { return 'iam_users'; }
}

// apps/Analytics/app/Models/UserShadow.php — analytics keeps one, table analytics_iam_users
final class UserShadow extends \Foundation\Iam\Shadows\UserShadow {}
```

The table is created by a migration iam publishes in `apps/Iam/database/shadows/`, extending
`Modulith\Migrations\ShadowMigration`; `migrate` runs it in the database of every module keeping
a copy. A copy is the package's own model: it always writes to its keeper's database, whatever
context it is synced from.

```
iam: User saved / deleted ─► ShadowChanged event ─► analytics consumer ─► UserShadow::sync()
```

A copy refuses every write that does not come from `sync()`; a deleted source row becomes a soft
delete. To fill a copy created after the source:

```bash
php artisan modulith:shadows:want                                # on the keeper: asks each owner to announce again, to it alone
php artisan modulith:shadows:announce iam_users [--for=analytics]  # on the owner: re-announce every row now
```

Both address the announcement through the envelope's `recipients`: only the keeper that asked, or
the ones named with `--for`, rewrite their copy — the others skip it.

## Configuration

`php artisan vendor:publish --tag=modulith-config` publishes three files. The package reads them
only through typed classes in `Modulith\Config\`: `Modules`, `Streamer`, `RedisStream`,
`QueueStream`, `Rpc`.

`config/modulith.php`

| Key | Default | |
|---|---|---|
| `source` | `ManifestSource::class` | the module list provider |
| `with` | `env('WITH_MODULES', '*')` | modules booted by this process |
| `modules_path` | `apps` | directory scanned for modules |
| `modules_namespace` | `Apps` | namespace root of the modules |
| `foundation_path` | `foundation` | directory of what modules publish for each other |
| `foundation_namespace` | `Foundation` | its namespace root |
| `status_route` | `env('MODULITH_STATUS_ROUTE')` | path of the status route, `null` registers none |

`config/streamer.php`

| Key | Default | |
|---|---|---|
| `default` | `env('MODULITH_STREAMER_STREAM', 'default')` | stream of an event whose `stream()` returns `null` |
| `streams` | `default` on `redis` | named streams, each a `driver` and its options; modules add theirs |
| `guard` | `env('MODULITH_STREAMER_GUARD')` | `null` = automatic |
| `listen` | `[]` | `'event.name' => [Handler::class, ...]`, filled by the modules |
| `propagate` | `[]` | `Context` keys carried in the envelope headers |

Options of a stream, by driver:

| Driver | Option | Default | |
|---|---|---|---|
| any | `outbox` | `false` | write to `event_publications` with the business transaction; `default` reads `MODULITH_STREAMER_OUTBOX` |
| `redis` | `connection` | `default` | a `database.redis` connection |
| | `prefix` | `modulith:{stream}:` | keys `{prefix}{module}`; the `default` stream sets `modulith:events:` |
| | `block` | `5000` | read block window, ms |
| | `count` | `50` | entries per read |
| | `claim_after` | `60000` | reclaim a dead consumer's pending entries, ms |
| `queue` | `connection` | `null` | a `queue.connections` entry, `null` = the default one |
| | `prefix` | `modulith-{stream}-` | queues `{prefix}{module}` |
| | `sleep` | `1` | seconds to wait on an empty queue |

`config/rpc.php`

| Key | Default | |
|---|---|---|
| `services` | `[]` | contract → `module`, `local`, `remote`, filled by the modules |
| `default` | `env('MODULITH_RPC_TRANSPORT', 'http')` | transport of a host that names none |
| `transports` | `http` | named transports, each a `driver` and its options |
| `hosts` | `[]` | `'iam' => 'https://iam.internal'`, or `['url' => …, 'transport' => 'grpc']` |
| `secret` | `env('MODULITH_RPC_SECRET')` | signs every call |
| `signature_ttl` | `30` | seconds a signature stays valid |

### Layout of the source

```
src/
├── Providers/          ModulithServiceProvider · ModuleServiceProvider
├── Http/               Controllers/StatusController · Middleware/{VerifyRpcSignature, SetModuleContext}
├── Console/Commands/   MakeModule · ListModules · Doctor · CacheModules · ClearModules · PublishEvents · RepublishEvents · ConsumeEvents
│                       TrimEvents · ExportEvents · ImportEvents · AnnounceShadows · WantShadows
├── Console/Migrations/ MigrateCommand · StatusCommand · RollbackCommand · ResetCommand · RefreshCommand
│                       FreshCommand · RunsForEachModule
├── Config/             Modules · Streamer · RedisStream · QueueStream · Rpc
├── Contracts/
│   ├── Modules/        Source
│   ├── Stream/         Bus · Transport · Handler · Idempotent · RedeliversEnvelopes · TrimsStreams · TracksAcknowledgements
│   ├── Rpc/            Transport
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

MIT — see [LICENSE](LICENSE).
