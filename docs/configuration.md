# Configuration

`php artisan vendor:publish --tag=modulith-config` publishes `config/modulith.php`, the only
configuration file. The package reads it only through the classes in `Modulith\Config\`:
`Modules`, `Streamer`, `RedisStream`, `QueueStream` and `Rpc`. What a module's folder must look
like is not configuration: see [Conventions](modules.md#conventions).

| Key | Default | |
|---|---|---|
| `modules` | `[]` | `'iam' => ['host' => …]`: every module, and the URL of the ones running elsewhere |
| `runs` | `env('MODULITH_RUNS', '*')` | modules this process runs |
| `paths.modules` | `apps` | directory of the modules |
| `paths.foundation` | `foundation` | directory of what modules share with each other |
| `namespaces.modules` | `Apps` | root namespace of the modules |
| `namespaces.foundation` | `Foundation` | root namespace of the foundation |
| `rpc.transport` | `env('MODULITH_RPC_TRANSPORT', 'http')` | transport used when a host doesn't name one |
| `rpc.transports` | `http` | named transports, each with a `driver` and its options |
| `rpc.secret` | `env('MODULITH_RPC_SECRET', env('APP_KEY'))` | signs every call; must be the same in every process |
| `rpc.signature_ttl` | `30` | how long a signature stays valid, in seconds |
| `rpc.cache` | `env('MODULITH_RPC_CACHE_STORE')` | the cache store of the RPC answers, shared by every process; `null` for the default one |
| `events.stream` | `env('MODULITH_STREAM', 'default')` | stream used when an event's `stream()` returns `null` |
| `events.streams` | `default`, on `redis` | named streams, each with a `driver` and its options; modules can add their own |
| `events.listen` | `[]` | `'event.name' => [Handler::class, ...]`, filled by the modules |
| `events.guard` | `env('MODULITH_STREAM_GUARD')` | `null` means automatic |
| `events.propagate` | `[]` | `Context` keys copied into the envelope headers |
| `status_route` | `env('MODULITH_STATUS_ROUTE')` | path of the status route, `null` for none |

Stream options, by driver:

| Driver | Option | Default | |
|---|---|---|---|
| any | `outbox` | `false` | write to `event_publications` in the current transaction; the `default` stream reads `MODULITH_STREAM_OUTBOX` |
| `redis` | `connection` | `default` | a `database.redis` connection; the `default` stream reads `MODULITH_STREAM_CONNECTION` |
| | `key` | `modulith:{stream}` | the Redis stream every module writes to; the `default` stream uses `modulith:events` |
| | `block` | `5000` | how long a read waits, in ms |
| | `count` | `50` | entries per read |
| | `claim_after` | `60000` | after how long a dead consumer's pending entries are taken back, in ms |
| | `on_failure` | `block` | `block`: a failed entry is retried before any later one; `skip`: later entries go on |
| `queue` | `connection` | `null` | a `queue.connections` entry, `null` for the default one |
| | `key` | `modulith-{stream}` | queues are `{key}-{module}` |
| | `sleep` | `1` | seconds to wait when the queue is empty |

## Source layout

```
src/
├── Providers/          ModulithServiceProvider · ModuleServiceProvider · FoundationServiceProvider
├── Http/               Controllers/{StatusController, RpcController} · Middleware/{VerifyRpcSignature, SetModuleContext}
├── Console/Commands/   Install · MakeModule · DeleteModule · ListModules · Doctor · PurgeModules · UnusedPackages · CacheModules · ClearModules · PublishEvents · RepublishEvents · ConsumeEvents
│                       TrimEvents · ExportEvents · ImportEvents · AnnounceShadows · WantShadows
├── Console/Migrations/ MigrateCommand · StatusCommand · RollbackCommand · ResetCommand · RefreshCommand
│                       FreshCommand · RunsForEachModule
├── Console/            ModuleOption · ModuleGenerators · ModuleSeedCommand
├── Config/             Modules · Streamer · RedisStream · QueueStream · Rpc
├── Contracts/
│   ├── Stream/         Bus · Transport · Handler · Versioned · Idempotent · RedeliversEnvelopes · TrimsStreams · TracksAcknowledgements
│   ├── Rpc/            RpcTransport
│   └── Shadows/        Shadowed
├── Data/               Module · Envelope
├── Models/             ShadowModel
├── Migrations/         ShadowMigration
├── Jobs/               FailedJobProvider · BatchRepository · Databases
├── Traits/             ResolvesModule · ShadowSource
├── Support/            ModuleDeferredCallbacks · ModuleConcurrencyDriver · ModuleTaskDispatcher · ModuleFactories
├── Exceptions/         ModuleException · ConfigurationException
├── Events/             Event · ShadowChanged · ShadowWanted
├── Handlers/           SyncShadows · AnnounceShadowSource
├── Testing/            Boundaries · InteractsWithModules
├── Services/
│   ├── Modules/        ModuleRegistry · DiscoveryCache · ModuleContext · ModuleMigrations · ComposerAutoload
│   ├── Stream/         Emitter · Dispatcher · EnvelopeFactory · TransportManager · PayloadVersions · Outbox/{Writer, Relay, Archive}
│   ├── Rpc/            RpcService · RpcServices · LocalServices · RpcSignature · RpcTransportManager
│   └── Shadows/        ShadowRegistry
└── Transports/
    ├── Stream/         RedisStreamTransport · QueueTransport · ArrayTransport · NullTransport
    └── Rpc/            HttpRpcTransport · LocalRpcTransport
```

