<?php

declare(strict_types=1);

use Modulith\Tests\Support\WithoutIamTestCase;

uses(WithoutIamTestCase::class);

it('fails on a module running elsewhere that serves a contract but has no host', function () {
    config()->set('rpc.hosts', []);

    $this->artisan('modulith:doctor')
        ->expectsOutputToContain('[iam] runs elsewhere and serves Foundation\Iam\Contracts\IamService')
        ->assertFailed();
});
