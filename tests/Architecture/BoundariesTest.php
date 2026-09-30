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

it('reports the foundation naming a module', function () {
    $file = dirname(__DIR__).'/Fixtures/foundation/Iam/Leak.php';
    file_put_contents($file, "<?php\n\nnamespace Foundation\\Iam;\n\nuse Apps\\Iam\\Models\\User;\n");

    try {
        expect($this->app->make(Boundaries::class)->violations())->toBe(["{$file}: Apps\\Iam\\Models\\User"]);
    } finally {
        unlink($file);
    }
});
