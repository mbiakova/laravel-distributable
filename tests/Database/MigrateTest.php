<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Modulith\Tests\Support\ModuleAppTestCase;

uses(ModuleAppTestCase::class);

it('migrates each local module database on its owner connection', function () {
    $this->artisan('modulith:migrate')->assertSuccessful();

    expect(Schema::connection('iam_owner')->hasTable('iam_users'))->toBeTrue()
        ->and(Schema::connection('iam_owner')->hasTable('event_publications'))->toBeTrue()
        ->and(Schema::connection('iam_owner')->hasTable('event_consumptions'))->toBeTrue()
        ->and(Schema::connection('iam_owner')->hasTable('migrations'))->toBeTrue();
});
