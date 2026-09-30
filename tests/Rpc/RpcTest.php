<?php

declare(strict_types=1);

use Apps\Iam\Services\IamService as LocalIamService;
use Foundation\Iam\Contracts\IamService;
use Foundation\Iam\Services\TokenRpcService;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Modulith\Contracts\Rpc\RpcTransport;
use Modulith\Data\Module;
use Modulith\Exceptions\ConfigurationException;
use Modulith\Services\Modules\ModuleRegistry;
use Modulith\Services\Rpc\RpcSignature;
use Modulith\Services\Rpc\RpcTransportManager;
use Modulith\Tests\Support\ModuleAppTestCase;

uses(ModuleAppTestCase::class);

/**
 * @param  array<string, mixed>  $payload
 * @param  array<string, mixed>  $context
 */
function signedRpcHeaders(string $path, array $payload, array $context = []): array
{
    return app(RpcSignature::class)->headers($path, json_encode($payload), json_encode((object) $context));
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
        RpcSignature::NONCE_HEADER => 'n-1',
        RpcSignature::CONTEXT_HEADER => '{}',
        RpcSignature::SIGNATURE_HEADER => app(RpcSignature::class)->sign($timestamp, 'n-1', '/iam/rpc/v1/users/find', json_encode($payload), '{}'),
    ])->assertForbidden();
});

it('rejects a signed call replayed', function () {
    $payload = ['id' => 1];
    $headers = signedRpcHeaders('/iam/rpc/v1/users/find', $payload);

    $this->postJson('/iam/rpc/v1/users/find', $payload, $headers)->assertOk();
    $this->postJson('/iam/rpc/v1/users/find', $payload, $headers)->assertForbidden();
});

it('restores the propagated context on the called side', function () {
    $payload = [];

    $this->postJson('/iam/rpc/v1/context/echo', $payload, signedRpcHeaders('/iam/rpc/v1/context/echo', $payload, ['trace_id' => 'abc']))
        ->assertOk()->assertExactJson(['trace_id' => 'abc']);
});

it('rejects a context changed after signing', function () {
    $payload = [];

    $this->postJson('/iam/rpc/v1/context/echo', $payload, [
        ...signedRpcHeaders('/iam/rpc/v1/context/echo', $payload, ['trace_id' => 'abc']),
        RpcSignature::CONTEXT_HEADER => json_encode(['trace_id' => 'forged']),
    ])->assertForbidden();
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
            $request->header(RpcSignature::NONCE_HEADER)[0],
            '/iam/rpc/v1/users/find',
            $request->body(),
            $request->header(RpcSignature::CONTEXT_HEADER)[0],
            $request->header(RpcSignature::SIGNATURE_HEADER)[0],
        ));
});

it('caches a remote answer for as long as the answer says, and forgets it on demand', function () {
    $service = app(TokenRpcService::class);

    expect($service->token(0)['token'])->toBe('t1')
        ->and($service->token(60)['token'])->toBe('t2')
        ->and($service->token(60)['token'])->toBe('t2');

    $service->drop();

    expect($service->token(60)['token'])->toBe('t3');
});

it('routes a call to the transport its module host names, registered with extend()', function () {
    config()->set('rpc.transports.grpc', ['driver' => 'grpc', 'port' => 50051]);
    config()->set('rpc.hosts.iam', ['url' => 'grpc://iam', 'transport' => 'grpc']);

    app(RpcTransportManager::class)->extend('grpc', fn ($app, array $config): RpcTransport => new class($config) implements RpcTransport
    {
        /** @param array<string, mixed> $config */
        public function __construct(private array $config) {}

        public function invoke(Module $module, string $resource, string $operation, array $payload = []): mixed
        {
            return ['via' => 'grpc', 'port' => $this->config['port'], 'call' => "{$module->name}/{$resource}/{$operation}"];
        }
    });

    expect(app(RpcTransport::class)->invoke(app(ModuleRegistry::class)->get('iam'), 'users', 'find'))
        ->toBe(['via' => 'grpc', 'port' => 50051, 'call' => 'iam/users/find']);
});

it('fails loudly on an RPC transport nobody declared', function () {
    config()->set('rpc.hosts.iam', ['url' => 'x', 'transport' => 'amqp']);

    app(RpcTransport::class)->invoke(app(ModuleRegistry::class)->get('iam'), 'users', 'find');
})->throws(ConfigurationException::class, 'RPC transport [amqp] is not declared');

it('reads a missing record as null', function () {
    Http::fake(['iam.test/*' => Http::response(null, 404)]);

    expect(app(RpcTransport::class)->invoke(app(ModuleRegistry::class)->get('iam'), 'users', 'find', ['id' => 9]))->toBeNull();
});
