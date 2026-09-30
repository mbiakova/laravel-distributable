<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Services
    |--------------------------------------------------------------------------
    | Each module declares the contracts it serves in its own config/rpc.php,
    | merged here: the local implementation is bound when the module runs in
    | this process, the remote one — calling it over the network — otherwise.
    |
    |   IamService::class => [
    |       'module' => 'iam',
    |       'local' => LocalIamService::class,
    |       'remote' => RemoteIamService::class,
    |   ],
    */

    'services' => [],

    // Base URL of each module when it runs elsewhere: 'iam' => 'https://iam.internal'.
    'hosts' => [],

    // Shared secret signing every call between modules, and how long a signature stays valid.
    'secret' => env('MODULITH_RPC_SECRET', ''),
    'signature_ttl' => 30,

];
