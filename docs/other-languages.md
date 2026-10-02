# Services in other languages

A service written in another language (a Node gateway, a Go worker) can take part: it emits and
consumes events on the stream, and calls the modules over RPC. The package doesn't ship a client
for it; this is what that client has to speak.

Declare it in `modulith.modules` like a module that runs elsewhere, with its `host`. A consumer
acknowledges without reading any entry whose `emitter` isn't a declared module, and a PHP module
calls it through a contract and an `RpcService` in `foundation/{Name}/`, like any other module.

Everything that leaves a process is JSON: the envelope on the stream and in the queues, the body
and the answer of an RPC call. Nothing is serialized in a PHP format. Only the RPC answer cache is
PHP's own, and no other service needs to read it.

| The service wants to | It does |
|---|---|
| announce something | [writes an envelope to the stream](#events-on-redis) |
| react to a module's events | [reads the stream](#events-on-redis) with its own consumer group |
| ask a module something | [calls its RPC route](#rpc-over-http), signed |
| be asked by a module | [answers that same route](#answering-a-module) |
| own data the modules keep a copy of | [announces each change](#being-the-source-of-a-copy) |

## Events on Redis

| | |
|---|---|
| Stream | the stream's `key`, `modulith:events` for the `default` stream |
| Entry | `XADD {key} * envelope {json}`: one field, `envelope`, holding [the envelope](events.md#the-envelope) as JSON |
| `emitter` | the service's name in `modulith.modules` |
| `id` | a UUID, unique per event: the consumption guard keys on it |
| Consuming | one consumer group per consuming module, named after it, created at `0`; acknowledge with `XACK` once handled |

## RPC over HTTP

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

## Answering a module

A module calls the service exactly as it calls another module: the contract and its `RpcService`
are in `foundation/{Name}/`, and the service's `host` is in `modulith.modules`. The service
receives the request above on `POST /{name}/rpc/{method}`, checks the signature, and answers the
return value as JSON, or a 404 for `null`. Any other status is an error for the caller.

To call it over something else than this HTTP format (gRPC, an existing REST API), give its host a
transport of your own: see [RPC](rpc.md).

## Being the source of a copy

A module can keep a [copy](shadows.md) of rows the service owns. The shape of the copy is declared
in PHP as usual, with the service as its owner:

```php
// foundation/Identity/Shadows/UserShadow.php
abstract class UserShadow extends \Modulith\Models\ShadowModel
{
    public static function owner(): string { return 'identity'; }

    public static function sourceTable(): string { return 'users'; }
}
```

The service then announces each change with a `modulith.shadow.changed` event:

```json
{
  "id": "0191f3c2-8a41-7c2e-9b55-3f1c7d0a4e88",
  "emitter": "identity",
  "name": "modulith.shadow.changed",
  "payload": { "source": "users", "key": 42, "attributes": { "name": "Ada" } },
  "headers": {},
  "emitted_at": "2026-09-30T13:22:41.512000Z"
}
```

`source` is the `sourceTable()` of the copy, `key` the row's id (an integer or a string),
`attributes` the columns of the copy. A deleted row is announced with a `deleted_at` among the
attributes. Each module that keeps a copy writes it in its own database when it consumes the event.

