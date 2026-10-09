# Events

Events between modules are [laravel-microservices' events](https://github.com/mbiakova/laravel-microservices/blob/main/docs/events.md):
each module is a service, named after it. The envelope, versions, streams, transports, the outbox
and the consumption guard are described there. This page covers what a module adds.

Events go through the stream even when both modules run in the same process, so they behave the
same in every setup.

| | In a module |
|---|---|
| the emitter | the module the event class belongs to: `Apps\Iam\Events\UserRegistered` is emitted by `iam` |
| the handlers | declared in `$handlers` of the module's service provider, merged only where the module runs |
| a handler runs | in its module's [context](../README.md#the-module-context): its queries land in its module's database |
| the outbox | `event_publications` in the emitting module's database, inside the module's transaction |
| the guard | `event_consumptions` in the consuming module's database |
| versions | the payload class sits in the emitter's foundation, declared in `FoundationServiceProvider::$payloads` |
| streams | a module can add its own in its `config/microservices.php` |

```php
// apps/Analytics/app/Providers/AnalyticsServiceProvider.php
protected array $handlers = ['iam.user.registered' => [RecordSignup::class]];

// foundation/FoundationServiceProvider.php
protected array $payloads = ['iam.user.registered' => UserRegisteredPayload::class];
```

## Consuming and publishing per module

`--module` on any command runs it in that module, so it names the consuming or publishing module:

```bash
php artisan microservices:events:consume --module=analytics   # one consumer per module and stream
php artisan microservices:events:publish                      # every local module's outbox; --module=iam for one
```

Run one consumer per module: two would break the order it reads in. Run one publisher per module
set: two would publish an outbox out of order.

In a test, `Distributable\Testing\ModuleAware::receive('analytics', 'iam.user.registered', [...])`
hands the event to analytics' handlers, in analytics' context, without the stream or iam.
