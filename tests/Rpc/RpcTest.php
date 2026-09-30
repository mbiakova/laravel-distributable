<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Modules\Iam\Contracts\IamService;
use Modules\Iam\Services\LocalIamService;
use Modulith\Contracts\RpcTransport;
use Modulith\Services\ModuleRegistry;
use Modulith\Services\RpcSignature;
use Modulith\Tests\Support\ModuleAppTestCase;

uses(ModuleAppTestCase::class);

/** @param array<string, mixed> $payload */
function signedRpcHeaders(string $path, array $payload): array
{
    $timestamp = (string) time();

    return [
        RpcSignature::TIMESTAMP_HEADER => $timestamp,
        RpcSignature::SIGNATURE_HEADER => app(RpcSignature::class)->sign($timestamp, $path, json_encode($payload)),
    ];
}

it('binds the local implementation when the module runs in this process', function () {
    expect(app(IamService::class))->toBeInstanceOf(LocalIamService::class)
        ->and(app(IamService::class)->findUser(1))->toBe(['id' => 1, 'name' => 'ada']);
});

it('rejects an unsigned call', function () {
    $this->postJson('/iam/rpc/v1/users/find', ['id' => 1])->assertForbidden();
});

it('answers a signed call through the module local implementation', function () {
    $payload = ['id' => 1];

    $this->postJson('/iam/rpc/v1/users/find', $payload, signedRpcHeaders('/iam/rpc/v1/users/find', $payload))
        ->assertOk()
        ->assertExactJson(['id' => 1, 'name' => 'ada']);
});

it('rejects a stale signature', function () {
    $timestamp = (string) (time() - 3600);
    $payload = ['id' => 1];

    $this->postJson('/iam/rpc/v1/users/find', $payload, [
        RpcSignature::TIMESTAMP_HEADER => $timestamp,
        RpcSignature::SIGNATURE_HEADER => app(RpcSignature::class)->sign($timestamp, '/iam/rpc/v1/users/find', json_encode($payload)),
    ])->assertForbidden();
});

it('restores the propagated context on the called side', function () {
    $payload = [];

    $this->postJson('/iam/rpc/v1/context/echo', $payload, [
        ...signedRpcHeaders('/iam/rpc/v1/context/echo', $payload),
        RpcSignature::CONTEXT_HEADER => json_encode(['trace_id' => 'abc']),
    ])->assertOk()->assertExactJson(['trace_id' => 'abc']);
});

it('sends a signed POST to the module host and decodes the answer', function () {
    config()->set('streamer.propagate', ['trace_id']);
    Context::add('trace_id', 'abc');
    Http::fake(['iam.test/*' => Http::response(['id' => 1, 'name' => 'ada'])]);

    $answer = app(RpcTransport::class)->invoke(app(ModuleRegistry::class)->get('iam'), 'users', 'find', ['id' => 1]);

    expect($answer)->toBe(['id' => 1, 'name' => 'ada']);

    Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'http://iam.test/iam/rpc/v1/users/find'
        && $request->header(RpcSignature::CONTEXT_HEADER)[0] === '{"trace_id":"abc"}'
        && app(RpcSignature::class)->verify(
            $request->header(RpcSignature::TIMESTAMP_HEADER)[0],
            '/iam/rpc/v1/users/find',
            $request->body(),
            $request->header(RpcSignature::SIGNATURE_HEADER)[0],
        ));
});

it('reads a missing record as null', function () {
    Http::fake(['iam.test/*' => Http::response(null, 404)]);

    expect(app(RpcTransport::class)->invoke(app(ModuleRegistry::class)->get('iam'), 'users', 'find', ['id' => 9]))->toBeNull();
});
