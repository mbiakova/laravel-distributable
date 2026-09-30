<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Streams
    |--------------------------------------------------------------------------
    | Named streams, like queue connections: each has a driver and that
    | driver's options. An event travels on the stream its stream() method
    | names, or on the default one. A module declares its own streams in its
    | config/streamer.php fragment. Drivers: `redis` (Redis Streams), `queue`
    | (a Laravel queue connection, no Redis needed), `array` (in memory, for
    | tests), `null` (drops everything). Any other driver resolves through a
    | creator registered on the transport manager with extend().
    */

    'default' => env('MODULITH_STREAMER_STREAM', 'default'),

    'streams' => [

        // Nothing is trimmed on write: modulith:events:trim drops only what every consumer group acknowledged.
        'default' => [
            'driver' => env('MODULITH_STREAMER_DRIVER', 'redis'),
            'outbox' => env('MODULITH_STREAMER_OUTBOX', false), // true: written with the business transaction, published by modulith:events:publish
            'connection' => env('MODULITH_STREAMER_REDIS_CONNECTION', 'default'),
            'prefix' => 'modulith:events:',
            'block' => 5_000,        // read block window, ms
            'count' => 50,           // entries per read
            'claim_after' => 60_000, // reclaim entries a dead consumer left pending, ms
        ],

        // 'jobs' => [
        //     'driver' => 'queue',
        //     'connection' => env('MODULITH_STREAMER_QUEUE_CONNECTION'),
        //     'prefix' => 'modulith-events-',
        //     'sleep' => 1,         // seconds to wait when the queue is empty
        // ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Consumption guard
    |--------------------------------------------------------------------------
    | Marks (event, handler) in `event_consumptions` inside the handler's own
    | transaction, so a redelivery is a no-op. Left null it turns itself on
    | exactly when delivery becomes at-least-once — the outbox, or a transport
    | that implements Contracts\RedeliversEnvelopes. Set it to force either way.
    */

    'guard' => env('MODULITH_STREAMER_GUARD'),

    // 'event.name' => [Handler::class, …] — each module contributes its own map through
    // its config/streamer.php fragment; a node only listens for the modules it boots.
    'listen' => [],

    /*
    |--------------------------------------------------------------------------
    | Context propagation
    |--------------------------------------------------------------------------
    | Keys of Laravel's Context copied into the envelope headers on emit, and
    | restored around each handler on the consuming side — a trace id, a
    | locale: whatever must follow a fact from one module to another.
    */

    'propagate' => [],

];
