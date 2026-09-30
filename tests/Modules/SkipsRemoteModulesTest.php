<?php

declare(strict_types=1);

use Foundation\Iam\Contracts\IamService;
use Foundation\Iam\Services\IamRpcService;
use Illuminate\Support\Facades\Http;
use Modulith\Tests\Support\WithoutIamTestCase;

uses(WithoutIamTestCase::class);

it('does not register a module this process does not boot', function () {
    $this->get('/iam/api/v1/ping')->assertNotFound();

    expect(config('iam.flag'))->toBeNull();
});

it('binds the foundation RpcService of a module running elsewhere, and caches its reads', function () {
    Http::fake(['iam.test/*' => Http::response(['id' => 1, 'name' => 'ada'])]);

    $iam = app(IamService::class);

    expect($iam)->toBeInstanceOf(IamRpcService::class)
        ->and($iam->findUser(1))->toBe(['id' => 1, 'name' => 'ada'])
        ->and($iam->findUser(1))->toBe(['id' => 1, 'name' => 'ada']);

    Http::assertSentCount(1);
});
