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
    | Each module is a service of laravel-microservices: its calls and its
    | events are configured in config/microservices.php.
    */

    'modules' => [
        // 'iam' => ['host' => env('IAM_HOST')],
    ],

    // Modules booted by THIS process: '*' = all, or a comma-separated list.
    'runs' => env('RUN_MODULES', '*'),

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

    // Path answering which modules this process runs (e.g. '/'), or null to register nothing.
    'status_route' => env('MODULES_STATUS_ROUTE'),

];
