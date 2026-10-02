# Events

## How an event travels

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

## The envelope

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

## Versioning a payload

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

## Context propagation

```php
// config/modulith.php
'events' => ['propagate' => ['trace_id', 'locale']],
```

These keys of Laravel's `Context` are copied into the envelope headers when the event is emitted,
and restored around each handler. RPC calls carry them too.

## Transports

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

## The outbox

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

## Consuming

```bash
php artisan modulith:events:consume [--module=analytics] [--stream=default]
```

The command reads one stream as the consuming module, for every emitting module on it. Run one
process per stream, like `queue:work`. On `SIGTERM` it finishes the current message and stops.

## Idempotent handlers

When delivery is at-least-once, each handler run is guarded: an `(event_id, handler)` row is
inserted into `event_consumptions` in the same transaction as the handler's writes. If the event is
delivered again, the row is already there and the handler doesn't run. If the handler throws, the
row is rolled back and the event is retried. The guard is enabled automatically with the outbox or
with a transport that implements `Contracts\Stream\RedeliversEnvelopes` (`redis` and `queue` do).
A handler that is already idempotent can implement `Contracts\Stream\Idempotent` to skip it.

