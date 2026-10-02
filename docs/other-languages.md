# Services in other languages

A service written in another language (a Node gateway, a Go worker) can take part: it emits and
consumes events on the stream, and calls the modules over RPC. The package doesn't ship a client
for it; this is what that client has to speak.

Declare it in `modulith.modules` like a module that runs elsewhere, with its `host`. A consumer
acknowledges without reading any entry whose `emitter` isn't a declared module, and a PHP module
calls it through a contract and an `RpcService` in `foundation/{Name}/`, like any other module.

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

