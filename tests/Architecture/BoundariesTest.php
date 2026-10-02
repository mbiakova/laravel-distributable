<?php

declare(strict_types=1);

use Modulith\Testing\Boundaries;
use Modulith\Tests\Support\ModuleAppTestCase;

uses(ModuleAppTestCase::class);

it('finds no module naming another one in the fixture tree', function () {
    expect($this->app->make(Boundaries::class)->violations())->toBe([]);
});

it('reports a module importing another module', function () {
    $file = dirname(__DIR__).'/Fixtures/apps/Analytics/app/Leak.php';
    file_put_contents($file, "<?php\n\nnamespace Apps\\Analytics;\n\nuse Apps\\Iam\\Models\\User;\n");

    try {
        expect($this->app->make(Boundaries::class)->violations())->toBe(["{$file}: Apps\\Iam\\Models\\User"]);
    } finally {
        unlink($file);
    }
});

it('reports a module reaching into another module database, by its connection or its table', function () {
    $file = dirname(__DIR__).'/Fixtures/apps/Analytics/app/Leak.php';
    file_put_contents($file, "<?php\n\nnamespace Apps\\Analytics;\n\n\\DB::connection('iam_owner')->table('iam_users')->count();\n");

    try {
        expect($this->app->make(Boundaries::class)->violations())->toBe(["{$file}: 'iam_owner'", "{$file}: 'iam_users'"]);
    } finally {
        unlink($file);
    }
});

it('lets a module name its own tables and its copies of another module tables', function () {
    $file = dirname(__DIR__).'/Fixtures/apps/Analytics/app/Leak.php';
    file_put_contents($file, "<?php\n\nnamespace Apps\\Analytics;\n\n\\DB::table('analytics_iam_users')->count();\n");

    try {
        expect($this->app->make(Boundaries::class)->violations())->toBe([]);
    } finally {
        unlink($file);
    }
});

it('reports the application naming a module, in app/, routes/ or config/', function () {
    $root = sys_get_temp_dir().'/modulith-app-'.uniqid();
    mkdir($root.'/app/Http', recursive: true);
    $this->app->useAppPath($root.'/app');
    $file = $root.'/app/Http/Leak.php';
    file_put_contents($file, "<?php\n\nnamespace App\\Http;\n\nuse Apps\\Iam\\Models\\User;\nuse Foundation\\Iam\\Contracts\\IamService;\n");

    try {
        expect($this->app->make(Boundaries::class)->violations())->toBe(["{$file}: Apps\\Iam\\Models\\User"]);
    } finally {
        unlink($file);
        rmdir($root.'/app/Http');
        rmdir($root.'/app');
        rmdir($root);
    }
});

it('reports the foundation naming a module', function () {
    $file = dirname(__DIR__).'/Fixtures/foundation/Iam/Leak.php';
    file_put_contents($file, "<?php\n\nnamespace Foundation\\Iam;\n\nuse Apps\\Iam\\Models\\User;\n");

    try {
        expect($this->app->make(Boundaries::class)->violations())->toBe(["{$file}: Apps\\Iam\\Models\\User"]);
    } finally {
        unlink($file);
    }
});
