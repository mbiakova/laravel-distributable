# Each module's data

## Connections

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
[shadow](shadows.md) of them.

## Models and transactions

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

In tests, use `Distributable\Testing\InteractsWithModules`:

```php
$user = $this->inModule('iam', fn () => User::query()->create(['name' => 'ada']));   // iam database
```

The trait also turns on Laravel's console events in tests, so `$this->artisan('iam:sync')` runs
the command in its module's context, as the real console does.

To test a module without the module that emits its events, hand it the event directly. Its handlers
run in its context, with no stream and no consumer:

```php
$this->receive('analytics', 'iam.user.registered', ['id' => 42]);   // emitter: iam, read from the name
```

## Migrations

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

## Queued jobs

Failed jobs and job batches are stored in the database of the module the job class belongs to.
`queue:failed`, `queue:retry` and `Bus::findBatch()` read from the databases of the modules this
process runs. This works when Laravel stores them in a database (the `database-uuids` failer and
database batches). The tables come from the application's `database/migrations`, which run in every
module database.

