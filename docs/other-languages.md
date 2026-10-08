# Services in other languages

A service written in another language (a Node gateway, a Go worker) takes part as described in
[laravel-microservices](https://github.com/mbiakova/laravel-microservices/blob/main/docs/other-languages.md):
it writes and reads envelopes on the stream, and calls the modules over signed HTTP. To the
modules, it is one more service.

| In a distributable application | |
|---|---|
| declare it | in `config/microservices.php` under `services`, with its `host` |
| call a module | `POST {host}/{module}/rpc/{method}`: each module answers on its own name |
| be called by a module | its contract and `RpcService` sit in `foundation/{Name}/`, like a module's |
| be the source of a copy | the copy's shape sits in `foundation/{Name}/Shadows/`, with the service as `owner()` |

The skeleton's [`examples/node-billing`](https://github.com/mbiakova/laravel-skeleton/tree/main/examples/node-billing)
is such a service: it handles `iam.user.registered`, asks iam the user's name over RPC, and
announces `billing.account.opened`.
