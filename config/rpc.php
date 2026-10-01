<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Services
    |--------------------------------------------------------------------------
    | Filled from each module's foundation/{Module}/rpc.php, which maps its
    | contracts to their RpcService: `IamService::class => IamRpcService::class`.
    | When the module runs here, its own {Module}\Services\{Contract} is bound
    | instead.
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
