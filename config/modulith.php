<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Modules
    |--------------------------------------------------------------------------
    | Every module of the application, by name: apps/Iam is `iam`. What the
    | package can read from a module's folder (its namespace, its provider,
    | whether it has a database) is never declared here. `host` is the URL of
    | a module when it runs in another process, or ['url' => …, 'transport' => …].
    */

    'modules' => [
        // 'iam' => ['host' => env('MODULITH_IAM_HOST')],
    ],

    // Modules booted by THIS process: '*' = all, or a comma-separated list.
    'runs' => env('MODULITH_RUNS', '*'),

    // {paths.modules}/{Module}/app is autoloaded as {namespaces.modules}\{Module}\, and
    // {paths.foundation}/{Module} as {namespaces.foundation}\{Module}\: no entry to add to composer.json.
    'paths' => [
        'modules' => 'apps',
        'foundation' => 'foundation',
    ],

    'namespaces' => [
        'modules' => 'Apps',
        'foundation' => 'Foundation',
    ],

    /*
    |--------------------------------------------------------------------------
    | Calls between modules
    |--------------------------------------------------------------------------
    | Named transports, like queue connections; a driver other than `http`
    | comes from RpcTransportManager::extend(). A module's host may name the
    | one its calls travel on.
    */

    'rpc' => [
        'transport' => env('MODULITH_RPC_TRANSPORT', 'http'),

        'transports' => [
            'http' => ['driver' => 'http'],
        ],

        // Signs every call; the caller and the called process must share it, so it falls back to APP_KEY.
        'secret' => env('MODULITH_RPC_SECRET', env('APP_KEY', '')),
        'signature_ttl' => 30,

        // The cache store of the RpcService answers, shared by every process: the caller keeps an
        // answer, the owner forgets it when it writes. Null is the default store.
        'cache' => env('MODULITH_RPC_CACHE_STORE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Events
    |--------------------------------------------------------------------------
    | Named streams, like queue connections: each has a driver and that
    | driver's options. An event travels on the stream its stream() method
    | names, or on the default one. Drivers: `redis` (Redis Streams), `queue`
    | (a Laravel queue connection, no Redis needed), `array` (in memory, for
    | tests), `null` (drops everything). Any other driver resolves through a
    | creator registered on the transport manager with extend(). A module adds
    | its streams and its handlers in its own config/modulith.php.
    */

    'events' => [
        'stream' => env('MODULITH_STREAM', 'default'),

        'streams' => [

            // Nothing is trimmed on write: modulith:events:trim drops only what every consumer group acknowledged.
            'default' => [
                'driver' => env('MODULITH_STREAM_DRIVER', 'redis'),
                'outbox' => env('MODULITH_STREAM_OUTBOX', false), // true: written with the business transaction, published by modulith:events:publish
                'connection' => env('MODULITH_STREAM_CONNECTION'), // null: the driver's own default connection
                'key' => 'modulith:events', // the Redis stream every module writes to, or the queue prefix
                'block' => 5_000,           // read block window, ms
                'count' => 50,              // entries per read
                'claim_after' => 60_000,    // reclaim entries a dead consumer left pending, ms
                'on_failure' => 'block',    // block: a failed entry is retried before later ones; skip: later ones go on
            ],

            // 'jobs' => [
            //     'driver' => 'queue',
            //     'connection' => env('MODULITH_QUEUE_CONNECTION'),
            //     'key' => 'modulith-jobs', // each module reads {key}-{module}
            //     'sleep' => 1,             // seconds to wait when the queue is empty
            // ],

        ],

        // 'event.name' => [Handler::class, …]; a process only listens for the modules it runs.
        'listen' => [],

        // Marks (event, handler) in `event_consumptions` inside the handler's transaction, so a
        // redelivery is a no-op. Null turns it on exactly when delivery is at-least-once.
        'guard' => env('MODULITH_STREAM_GUARD'),

        // Keys of Laravel's Context carried in the envelope headers and restored around each handler.
        'propagate' => [],
    ],

    // Path answering which modules this process runs (e.g. '/'), or null to register nothing.
    'status_route' => env('MODULITH_STATUS_ROUTE'),

];
