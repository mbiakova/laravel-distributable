# Laravel Modulith

Design your Laravel application as modules built like independent services — each one owns its
database, and talks to the others only through events and signed calls — in a single codebase.
Deploy them grouped in **one process** while the load is low; give any module **its own
service** when it needs one — by changing an environment variable, not the code.

```bash
composer require mk-josias/laravel-modulith
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
- [Roadmap](#roadmap)

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
│ modules/Iam           modules/Analytics         modules/Transactions │
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

Three channels connect modules, and none of them is a direct call:

| Channel | For | Same process | Different processes |
|---|---|---|---|
| **Events** | announcing a fact, asynchronously | on the stream | on the stream |
| **RPC** | a synchronous read another module answers | contract bound to the local class | contract bound to a signed HTTP client |
| **Shadows** | reading another module's rows at local speed | a copy in your database, fed by events | same |

At boot, the package:

1. discovers the modules (a directory of `modules/` with a `modulith.php` marker) and autoloads
   each one under `Modules\{Module}\`;
2. binds every RPC contract, for all modules, to its local or remote implementation;
3. registers the provider of each module this process runs — which merges its config fragments
   (including its database connections and its event handlers), loads its routes, migrations,
   translations and commands.

A module this process does not run has no config, no routes, no handlers here — only a remote
binding for its contracts.

## Design

### Mechanism, not style

The package ships only what "a deployable module" cannot mean without. How to write an
application — response envelopes, DTOs, repositories, exception philosophy, authentication — is
left to you.

The rule applied to every piece: **it belongs here if violating it breaks a guarantee of the
package.** A model that does not extend `Models\Model` writes to another module's database, so
it is here. A module without a repository breaks nothing, so repositories are not.

### Every seam stays open

| Seam | Default | Replace with |
|---|---|---|
| `Contracts\Source` — the module list | `ManifestSource` | `modulith.source` |
| `Contracts\Transport` — the event stream | `redis`, `queue`, `array`, `null` | `TransportManager::extend()` |
| `Contracts\RpcTransport` — calls between modules | `HttpRpcTransport` | a container binding |

The package owns the **envelope format**: that is what keeps transports interchangeable.

### How it compares

| | nwidart/laravel-modules | Spring Modulith | **laravel-modulith** |
|---|---|---|---|
| Purpose | organise the code in modules | boundaries + events | organise the code in modules **and deploy them apart** |
| Data | one shared database | one datasource | **one database per module** |
| Between modules | direct calls | events + outbox | **events + ordered outbox, RPC, shadows** |
| Path to services | rewrite | new application | **`WITH_MODULES`** |

### Non-goals

- Toggling modules at runtime — the topology is fixed at boot; it is a deployment decision.
- A `composer.json` per module while modules deploy together: one `vendor/`, one lockfile.
- Orchestration (Kubernetes, proxies) — that is the application's infrastructure.
- Imposing a code style.

## Quick start

```
modules/Iam/
├── modulith.php                      marker: this directory is a module
├── app/                              Modules\Iam\
│   ├── Providers/IamServiceProvider.php
│   ├── Models/User.php
│   └── Events/UserRegistered.php
├── config/
│   ├── database.php                  its connections — present = the module has a database
│   └── streamer.php                  the events it listens to
├── database/migrations/
└── routes/api.php                    served under /iam/api/…
```

```php
// modules/Iam/modulith.php
return [];

// modules/Iam/app/Providers/IamServiceProvider.php
final class IamServiceProvider extends \Modulith\Providers\ModuleServiceProvider {}

// modules/Iam/app/Models/User.php — connection `iam`, table `iam_users`
final class User extends \Modulith\Models\Model {}
```

```php
// modules/Iam/config/database.php — merged into config/database.php when iam boots
return ['connections' => [
    'iam'       => ['driver' => 'pgsql', 'database' => 'iam', 'username' => 'iam_app'],   // reads and writes
    'iam_owner' => ['driver' => 'pgsql', 'database' => 'iam', 'username' => 'iam_owner'], // creates and alters tables
]];
```

```bash
php artisan modulith:migrate
```

Emit an event from one module, handle it in another:

```php
final class UserRegistered extends \Modulith\Events\Event
{
    public function __construct(private readonly int $id) {}

    public function name(): string { return 'iam.user.registered'; }

    public function payload(): array { return ['id' => $this->id]; }
}

app(\Modulith\Contracts\Bus::class)->emit(new UserRegistered($user->id));
```

```php
// modules/Analytics/app/Handlers/RecordSignup.php
final class RecordSignup implements \Modulith\Contracts\Handler
{
    public function handle(string $name, array $payload): void { /* ... */ }
}

// modules/Analytics/config/streamer.php
return ['listen' => ['iam.user.registered' => [RecordSignup::class]]];
```

```bash
php artisan modulith:events:consume --module=analytics
```

## Modules

### Declaring a module

A directory of `modules/` is a module **when it carries a `modulith.php` file**. The file is a
marker and returns `[]`; everything else follows from the directory name:

| | Module `Iam` |
|---|---|
| name | `iam` — the directory in snake_case, `^[a-z][a-z0-9_]*$` |
| namespace | `Modules\Iam`, autoloaded from `modules/Iam/app` — no entry in `composer.json` |
| provider | `Modules\Iam\Providers\IamServiceProvider` |
| database | yes, because `modules/Iam/config/database.php` exists |

### Which modules this process runs

```dotenv
WITH_MODULES=*                       # every module (the default)
WITH_MODULES=transactions,analytics  # only these
```

`Modulith\Services\ModuleRegistry` holds both views: `all()` — every declared module — and
`local()` — the ones this process boots. `get($name)` fails loudly on an unknown name;
`forClass($class)` resolves the module owning a class from its namespace.

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
| `database/migrations/` | Registered as a migrations path. |
| `lang/` | Loaded under the module name: `__('iam::messages.hello')`. |
| Artisan commands | Every `Illuminate\Console\Command` found in `app/` is registered (console only). |

### Declaring modules another way

The marker scan is the default `Modulith\Contracts\Source`. Point `modulith.source` at your own
implementation returning a `list<Modulith\Data\Module>`.

## A database per module

### Connections

A module with a database declares two connections in its own `config/database.php`:

| Connection | Role |
|---|---|
| `{module}` | The runtime connection: reads and writes rows, nothing else. |
| `{module}_owner` | Owns the tables: creates and alters them. Used only by `modulith:migrate`. |

What sits behind them is a deployment variable; the code is identical in every case — N
databases on one server, N schemas of one database, N servers, N sqlite files in tests. Several
modules may even point at the same database: the outbox filters its rows by emitter.

The boundary is held by the code, not by the server. **No foreign key crosses a module**:
reference another module's rows by bare id, or keep a [shadow](#read-only-copies-shadows).

### Models and transactions

`Modulith\Models\Model` resolves its module from its namespace, uses the module's connection
(`iam`) and prefixes its table with the module name (`iam_users`) unless `$table` is set. A class
outside any module namespace throws a `ModuleException` rather than guessing.

```php
final class RegisterUser
{
    use \Modulith\Traits\TransactsOnModule;

    public function __invoke(User $user): void
    {
        $this->transaction(function () use ($user): void {
            // runs on the iam connection, not the framework default
        });
    }
}
```

### Migrations

```bash
php artisan modulith:migrate [--pretend]
```

For every local module with a database, **one** `migrate` run on `{module}_owner` over: the
package's tables (`event_publications`, `event_consumptions`), the application's
`database/migrations`, the module's `database/migrations`, and the shadow migrations of the
copies it keeps. Migrations interleave by date; each database keeps its own history.

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
  "emitted_at": "2026-09-30T13:22:41.512000Z"
}
```

`emitter` is resolved from the event's namespace (override `Event::emitter()` otherwise).
Payloads evolve additively; a breaking change is a **new event name**.

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

Register your own — no fork, no pull request:

```php
app(\Modulith\Services\TransportManager::class)
    ->extend('kafka', fn ($app) => new KafkaTransport(/* ... */));
```

A transport implements `publish()`, a blocking `consume()` loop and `stop()`. Returning normally
from the consume callback acknowledges the message; throwing does not.

Redis keeps every entry until **every consumer group has acknowledged it** — nothing is trimmed
on write, so a stopped consumer or a module plugged in later loses nothing. Drop what everyone
has read on a schedule:

```bash
php artisan modulith:events:trim [--module=*]
```

### The outbox

With `MODULITH_STREAMER_OUTBOX=true`, `emit()` writes a row to `event_publications` **in the
emitting module's database**, inside the business transaction: the fact and its announcement
commit or roll back together. A single publisher process puts the rows on the wire:

```bash
php artisan modulith:events:publish [--module=*] [--batch=100] [--sleep=1] [--once]
```

**Order is a guarantee.** Rows are published in `sequence` order, and a failing row stops the
pass. That holds with **one publisher per module**; do not run two.

The outbox is also the archive: if the broker is emptied, rebuild the stream from it.

```bash
php artisan modulith:events:republish [--module=*] [--since=2026-09-01] [--force]
```

### Consuming

```bash
php artisan modulith:events:consume [--module=analytics]
```

Reads every module's stream as the consuming module. Stops after the current message on
`SIGTERM`.

### Idempotent handlers

With at-least-once delivery, every handler run is guarded: an `(event_id, handler)` mark is
inserted into `event_consumptions` **in the same transaction** as the handler's writes. A
redelivery finds the mark and does nothing; a handler that throws rolls its mark back and is
replayed. The guard turns itself on with the outbox or a transport implementing
`Contracts\RedeliversEnvelopes` (`redis` and `queue` do). A handler that is idempotent by nature
implements `Contracts\Idempotent` and skips it.

## Calls between modules (RPC)

For a synchronous read another module answers. The owning module publishes a contract and two
implementations:

```php
// modules/Iam/app/Contracts/IamService.php
interface IamService
{
    public function findUser(int $id): ?array;
}

// modules/Iam/app/Services/LocalIamService.php — bound when iam runs in this process
final class LocalIamService implements IamService
{
    public function findUser(int $id): ?array { /* query iam's own database */ }
}

// modules/Iam/app/Services/RemoteIamService.php — bound when iam runs elsewhere
final class RemoteIamService extends \Modulith\Services\RemoteService implements IamService
{
    public function findUser(int $id): ?array
    {
        return $this->remember("iam:user:{$id}", 3600, fn () => $this->call('users', 'find', ['id' => $id]));
    }
}
```

```php
// modules/Iam/config/rpc.php — read for every module, running here or not
return ['services' => [
    IamService::class => ['module' => 'iam', 'local' => LocalIamService::class, 'remote' => RemoteIamService::class],
]];

// modules/Iam/routes/rpc.php — the answering side, under /iam/rpc/…
Route::prefix('v1')->group(function () {
    Route::post('users/find', fn (Request $r, LocalIamService $iam) => $iam->findUser($r->integer('id')) ?? abort(404));
});
```

Other modules type-hint `IamService` and never know where iam runs. A remote call is a
`POST {host}/iam/rpc/v1/users/find`, signed with an HMAC over the timestamp, path and body; the
`rpc` middleware group rejects anything unsigned or stale. A 404 answers `null`.

```php
// config/rpc.php
'hosts'  => ['iam' => 'https://iam.internal'],
'secret' => env('MODULITH_RPC_SECRET'),
```

## Read-only copies (shadows)

When a module needs another module's rows at local speed — to join, filter, sort — it keeps a
**copy** in its own database, fed by events. The source module stays the only writer.

```php
// modules/Iam/app/Models/User.php — the source: these fields travel
final class User extends \Modulith\Models\Model implements \Modulith\Contracts\Shadowed
{
    use \Modulith\Traits\ShadowSource;

    protected array $shadowed = ['name'];
}

// modules/Iam/app/Shadows/UserShadow.php — the shape of a copy, declared once by iam
abstract class UserShadow extends \Modulith\Models\ShadowModel
{
    public static function source(): string { return User::class; }
}

// modules/Analytics/app/Models/UserShadow.php — analytics keeps one, table analytics_iam_users
final class UserShadow extends \Modules\Iam\Shadows\UserShadow {}
```

The table is created by a migration iam publishes in `modules/Iam/database/shadows/`, extending
`Modulith\Migrations\ShadowMigration`; `modulith:migrate` runs it in the database of every module
keeping a copy.

```
iam: User saved / deleted ─► ShadowChanged event ─► analytics consumer ─► UserShadow::sync()
```

A copy refuses every write that does not come from `sync()`; a deleted source row becomes a soft
delete. To fill a copy created after the source:

```bash
php artisan modulith:shadows:want             # on the keeper: asks each owner to announce again
php artisan modulith:shadows:announce iam_users   # on the owner: re-announce every row now
```

## Configuration

`php artisan vendor:publish --tag=modulith-config` publishes three files. The package reads them
only through typed classes in `Modulith\Config\`: `Modules`, `Streamer`, `RedisStream`,
`QueueStream`, `Rpc`.

`config/modulith.php`

| Key | Default | |
|---|---|---|
| `source` | `ManifestSource::class` | the module list provider |
| `with` | `env('WITH_MODULES', '*')` | modules booted by this process |
| `modules_path` | `modules` | directory scanned for modules |
| `modules_namespace` | `Modules` | namespace root of the modules |
| `status_route` | `env('MODULITH_STATUS_ROUTE')` | path of the status route, `null` registers none |

`config/streamer.php`

| Key | Default | |
|---|---|---|
| `transport` | `env('MODULITH_STREAMER_TRANSPORT', 'redis')` | `redis`, `queue`, `array`, `null` or your own |
| `outbox` | `env('MODULITH_STREAMER_OUTBOX', false)` | |
| `guard` | `env('MODULITH_STREAMER_GUARD')` | `null` = automatic |
| `listen` | `[]` | `'event.name' => [Handler::class, ...]`, filled by the modules |
| `propagate` | `[]` | `Context` keys carried in the envelope headers |
| `redis.connection` | `env('MODULITH_STREAMER_REDIS_CONNECTION', 'default')` | |
| `redis.prefix` | `modulith:events:` | |
| `redis.block` | `5000` | read block window, ms |
| `redis.count` | `50` | entries per read |
| `redis.claim_after` | `60000` | reclaim a dead consumer's pending entries, ms |
| `queue.connection` | `env('MODULITH_STREAMER_QUEUE_CONNECTION')` | `null` = the default queue connection |
| `queue.prefix` | `modulith-events-` | |
| `queue.sleep` | `1` | seconds to wait on an empty queue |

`config/rpc.php`

| Key | Default | |
|---|---|---|
| `services` | `[]` | contract → `module`, `local`, `remote`, filled by the modules |
| `hosts` | `[]` | `'iam' => 'https://iam.internal'` |
| `secret` | `env('MODULITH_RPC_SECRET')` | signs every call |
| `signature_ttl` | `30` | seconds a signature stays valid |

### Layout of the source

```
src/
├── Providers/          ModulithServiceProvider · ModuleServiceProvider
├── Http/               Controllers/StatusController · Middleware/VerifyRpcSignature
├── Console/Commands/   Migrate · PublishEvents · RepublishEvents · ConsumeEvents · TrimEvents
│                       AnnounceShadows · WantShadows
├── Config/             Modules · Streamer · RedisStream · QueueStream · Rpc
├── Contracts/          Source · Bus · Transport · Handler · RedeliversEnvelopes · TrimsStreams
│                       Idempotent · RpcTransport · Shadowed
├── Data/               Module · Envelope
├── Models/             Model · ShadowModel
├── Migrations/         ShadowMigration
├── Traits/             ResolvesModule · TransactsOnModule · ShadowSource
├── Exceptions/         ModuleException · ConfigurationException
├── Events/             Event · ShadowChanged · ShadowWanted
├── Handlers/           SyncShadows · AnnounceShadowSource
├── Services/           ModuleRegistry · ManifestSource · ShadowRegistry · EnvelopeFactory
│   │                   Dispatcher · Emitter · TransportManager · RemoteService · RpcSignature
│   └── Outbox/         Emitter · Relay
└── Transports/         RedisStreamTransport · QueueTransport · ArrayTransport · NullTransport
                        HttpRpcTransport
```

## Roadmap

Planned, not shipped.

- **Tooling** — `modulith:make-module`, `modulith:list`, `modulith:cache`, `modulith:doctor`,
  Pest architecture presets.

## Testing

```bash
composer check   # pint + phpstan (level 6) + pest
```

## License

MIT — see [LICENSE](LICENSE).
