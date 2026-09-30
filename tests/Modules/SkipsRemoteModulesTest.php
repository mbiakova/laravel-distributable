<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Modules\Iam\Contracts\IamService;
use Modules\Iam\Services\RemoteIamService;
use Modulith\Tests\Support\WithoutIamTestCase;

uses(WithoutIamTestCase::class);

it('does not register a module this process does not boot', function () {
    $this->get('/iam/api/v1/ping')->assertNotFound();

    expect(config('iam.flag'))->toBeNull();
});

it('binds the remote implementation of a module running elsewhere, and caches its reads', function () {
    Http::fake(['iam.test/*' => Http::response(['id' => 1, 'name' => 'ada'])]);

    $iam = app(IamService::class);

    expect($iam)->toBeInstanceOf(RemoteIamService::class)
        ->and($iam->findUser(1))->toBe(['id' => 1, 'name' => 'ada'])
        ->and($iam->findUser(1))->toBe(['id' => 1, 'name' => 'ada']);

    Http::assertSentCount(1);
});
