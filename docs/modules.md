# Modules

## Declaring a module

A module is a name in `distributable.modules`. Its folder is `apps/` followed by the name in
StudlyCase, and everything else is derived from that folder. The configuration only holds what the
folder can't tell, such as the URL of a module that runs elsewhere:

```php
// config/distributable.php (php artisan vendor:publish --tag=distributable-config)
'modules' => [
    'iam'       => ['host' => env('IAM_HOST')],
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
php artisan distributable:make-module point_of_sale [--database]   # creates apps/PointOfSale and foundation/PointOfSale, and declares it
php artisan distributable:delete-module point_of_sale [--force]    # deletes its folders, its declaration and its composer.json entries; its database is left as it is
php artisan distributable:list                                     # lists the modules, where they run, their database and host
php artisan distributable:purge                                    # deletes the folder of the modules this process doesn't run
php artisan distributable:doctor                                   # checks that the modules can run, see "Checking the application"
```

`distributable:make-module` also adds the module and the foundation to the `autoload.psr-4` of your
`composer.json`, and the module's tests to `autoload-dev`. The application doesn't read those entries: they are for the tools that read
`composer.json` without booting Laravel, such as your IDE. They follow `paths.modules` and
`namespaces.modules`; if you change these later, `distributable:doctor` reports the entries gone stale.
A module outside the project gets none. `distributable:purge` can delete a module the entries name:
Composer accepts an entry whose folder is gone.

## Generating code in a module

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
`Distributable\Support\ModuleFactories::factoryName()` and `modelName()`.

## Running a command in a module

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
module's model then throws `ModuleException`, since no database belongs to it.

`migrate --seed` and `migrate:fresh --seed` seed each module database with that module's
`DatabaseSeeder`. A module without one is skipped.

## Conventions

A module is laid out like a Laravel application, by convention, the way Laravel itself expects
`app/Models` or `routes/web.php`. You choose the four roots in the configuration; inside them, the
package reads everything from the folder and the namespace, so there is nothing to register.

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

These conventions are what the features are built on. Because the folder says everything, a module
is found without being registered, `distributable:purge` can delete a whole folder, `Boundaries` knows
which module owns a file, and an RPC service knows which module it calls. With a setting for each,
those guarantees would depend on configuration that every process must get right.

## Which modules a process runs

```dotenv
RUN_MODULES=*                       # every module (the default)
RUN_MODULES=transactions,analytics  # only these
```

`Distributable\Services\Modules\ModuleRegistry` gives you both lists: `all()` returns every declared
module and `local()` the ones this process runs. `get($name)` throws on an unknown name, and
`forClass($class)` returns the module a class belongs to, based on its namespace.

In production, `php artisan optimize` runs `distributable:cache`, which writes what the module folders
tell (namespaces, databases, copies and shadow sources) to `bootstrap/cache/distributable.php`, and the
application reads that file instead of the folders. `optimize:clear` (or `distributable:clear`) deletes
it. The cache never decides which modules exist: `distributable.modules` does.

## Modules this process doesn't run

The package only autoloads the classes of the modules in `RUN_MODULES`, plus the foundation. A
class of any other module is never loaded, even if its file is on disk: using it throws a
`ModuleException`.

```
[Apps\Iam\Services\IamService] belongs to module [iam], which this process does not run (RUN_MODULES): go through its foundation contract or an event.
```

This catches, at runtime, a call that `Boundaries` would have caught in your tests. A process still
knows that the other modules exist, because `config/distributable.php` declares them: it listens to
their events, and calls them through the RPC services of the foundation.

When you build an image for some modules only, you can delete the folders of the others:

```bash
RUN_MODULES=analytics php artisan distributable:purge --force
```

Run it in your Dockerfile, after copying the code and before `composer dump-autoload`. Starting an
image with a module in `RUN_MODULES` whose folder was purged fails at boot, with the name of the
module.

## The dependencies of a module

The package reads no `composer.json` of its own accord: by default every dependency is in the root
one. A module can declare the libraries only it uses in `{module}/composer.json`, merged into the
root file by [wikimedia/composer-merge-plugin](https://github.com/wikimedia/composer-merge-plugin).
There is still one `composer.lock` and one `vendor/`.

```json
// apps/Iam/composer.json
{ "require": { "spatie/laravel-permission": "^8.3" } }

// composer.json
"extra": { "merge-plugin": { "include": ["apps/*/composer.json"] } }
```

An image built for some modules can then leave out what only the others require.
`distributable:unused-packages` prints those packages. Run it before `distributable:purge`, which deletes the
files it reads, and name the packages to Composer so that it removes them and changes no other
version:

```bash
UNUSED="$(RUN_MODULES=analytics php artisan distributable:unused-packages)"   # spatie/laravel-permission
RUN_MODULES=analytics php artisan distributable:purge --force
[ -z "$UNUSED" ] || { composer update $UNUSED --no-dev --no-scripts; rm -f bootstrap/cache/packages.php bootstrap/cache/services.php; }
composer dump-autoload --optimize --no-dev
```

A package the root `composer.json` or a module this process runs also requires is never listed.

With `MODULES_STATUS_ROUTE=/`, the process returns the modules it runs:

```json
{ "message": "Hello from laravel-distributable", "modules": ["transactions", "analytics"], "status": "ok" }
```

## The module service provider

A provider that extends `Distributable\Providers\ModuleServiceProvider` loads the following from the
module directory:

| Source | What happens |
|---|---|
| `config/*.php` | Merged into the root config file with the same name (see below). Once `config:cache` has run, the cache already holds the merge and the files are not read again. |
| `routes/{name}.php` | Loaded under the `{module}/{name}` prefix, in the `{name}` middleware group if the application defines one. |
| `lang/` | Loaded under the module name: `__('iam::messages.hello')`. |
| `resources/views/` | Loaded under the module name: `view('iam::welcome')`. Anonymous components of `resources/views/components` and class components of `app/View/Components` are `<x-iam::alert />`. |
| Artisan commands | Every `Illuminate\Console\Command` in `app/` is registered (console only). |
| `$listen` on the provider | The Laravel events the module listens to, written as in `EventServiceProvider::$listen`. Each listener runs in the module, whichever module dispatched the event; a queued one keeps working as Laravel queues it, and an after-commit one runs once the transaction commits, still in the module. |
| `schedule(Schedule $schedule)` on the provider | The module's scheduled tasks. Only a process that runs the module schedules them, and `schedule:run` runs each one in the module, closures included. A task scheduled elsewhere runs in no module. |

How the config merge works, and what it can't do:

| Case | Result |
|---|---|
| a list (`['a', 'b']`) | gains the items it lacks; a module can't remove or replace an item, so set the whole list in the root config |
| any other array, integer keys included (`[404 => …]`) | merged key by key |
| a scalar two local modules set differently | the module registered last wins in one process, while each keeps its own once they run apart: `distributable:doctor` reports it |
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

## The foundation

A module never uses another module's classes, because that import would break as soon as the
modules are deployed separately. What a module shares with the others (its RPC contracts and
services, the shape of its copies, its event names) goes in `foundation/{Module}/`, autoloaded as
`Foundation\{Module}\` (`distributable.paths.foundation`, `distributable.namespaces.foundation`). Any module can
use the foundation, and the foundation uses no module.

```
apps/Analytics ──► foundation/Iam ◄── apps/Iam
       └──────── never ──────────────┘
```

The package checks this rule for you, see [Checking the application](#checking-the-application).

## Checking the application

The package has two checks. Both report the same boundary violations, but they are meant for
different moments.

`distributable:doctor` checks that the modules can run with the current configuration. Run it when
you deploy, or in CI with the production environment:

```bash
php artisan distributable:doctor
```

| It reports | Example |
|---|---|
| a folder of `apps/` that `distributable.modules` doesn't declare | `[apps/Billing] is not declared in distributable.modules.` |
| a module whose service provider class doesn't exist | `[gateway] provider Apps\Gateway\Providers\GatewayServiceProvider does not exist.` |
| a local module with a database but no declared connection | `[iam] connection [iam_owner] is not declared.` |
| a module running elsewhere that serves a contract, with no host | `[iam] runs elsewhere and serves Foundation\Iam\Contracts\IamService, but distributable.modules.iam.host is not set.` |
| RPC contracts declared while the secret is empty | `Modules serve RPC contracts but microservices.rpc.secret is empty: set MICROSERVICES_RPC_SECRET or APP_KEY.` |
| a module using another module's classes, or the foundation or the application (`app/`, `routes/`, `config/`) using a module | `Boundary crossed: apps/Analytics/app/Models/Report.php: Apps\Iam\Models\User` |
| a module naming another module's connection, or a table that module's migrations create | `Boundary crossed: apps/Analytics/app/Models/Report.php: 'iam_users'` |
| two local modules setting one config key to different values | `Modules [analytics, iam] set config [iam.flag] to different values: in one process, the module registered last wins.` |

It lists every problem and exits with a non-zero code if there is at least one.

`Distributable\Testing\Boundaries` is the architecture check on its own, for your test suite. It
doesn't depend on the configuration: it reads the PHP files of every module, of the foundation and
of the application's `app/`, `routes/` and `config/`.

```php
// tests/Architecture/BoundariesTest.php
it('keeps the modules apart', function () {
    expect(app(\Distributable\Testing\Boundaries::class)->violations())->toBe([]);
});
```

