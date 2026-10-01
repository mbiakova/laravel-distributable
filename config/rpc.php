<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Services
    |--------------------------------------------------------------------------
    | Filled at boot by the application's FoundationServiceProvider, from its
    | $rpc map: each contract with its module and its RpcService.
    */

    'services' => [],

    // Named transports, like queue connections; a driver other than `http` comes from RpcTransportManager::extend().
    'default' => env('MODULITH_RPC_TRANSPORT', 'http'),

    'transports' => [
        'http' => ['driver' => 'http'],
    ],

    // Each module when it runs elsewhere: its base URL, or ['url' => …, 'transport' => 'grpc'].
    'hosts' => [],

    // Signs every call between modules; the caller and the called process must share it, so it falls back to APP_KEY.
    'secret' => env('MODULITH_RPC_SECRET', env('APP_KEY', '')),
    'signature_ttl' => 30,

];
