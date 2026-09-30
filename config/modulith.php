<?php

declare(strict_types=1);

use Modulith\Services\Modules\ManifestSource;

return [

    /*
    |--------------------------------------------------------------------------
    | Module source
    |--------------------------------------------------------------------------
    | Supplies the application's module list. The default discovers them from
    | the application tree: a directory of {modules_path} is a module when it
    | carries a modulith.php marker. Point this at your own
    | Modulith\Contracts\Modules\Source implementation to declare modules another way.
    */

    'source' => ManifestSource::class,

    // Modules booted by THIS process: '*' = all, or a comma-separated list.
    'with' => env('WITH_MODULES', '*'),

    // Directory scanned for modules, and their namespace root: {modules_path}/{Module}/app is
    // autoloaded as {modules_namespace}\{Module}\ — no entry to add to composer.json.
    'modules_path' => 'apps',
    'modules_namespace' => 'Apps',

    // What a module publishes for the others — contracts, shapes of copies, event names — lives
    // in {foundation_path}/{Module}, autoloaded as {foundation_namespace}\{Module}\. A module
    // imports the foundation, never another module.
    'foundation_path' => 'foundation',
    'foundation_namespace' => 'Foundation',

    // Path answering which modules this process runs (e.g. '/'), or null to register nothing.
    'status_route' => env('MODULITH_STATUS_ROUTE'),

];
