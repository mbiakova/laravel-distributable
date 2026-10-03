<?php

declare(strict_types=1);

use Foundation\Iam\Contracts\IamService;
use Foundation\Iam\Services\IamRpcService;
use Foundation\Iam\Services\TokenRpcService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Modulith\Contracts\Rpc\RpcTransport;
use Modulith\Data\Module;
use Modulith\Exceptions\ConfigurationException;
use Modulith\Exceptions\ModuleException;
use Modulith\Services\Modules\ModuleRegistry;
use Modulith\Services\Rpc\RpcSignature;
use Modulith\Services\Rpc\RpcTransportManager;
use Modulith\Tests\Support\ModuleAppTestCase;
use Modulith\Transports\Rpc\HttpRpcTransport;

uses(ModuleAppTestCase::class);

/**
 * @param  array<string, mixed>  $body
 * @param  array<string, mixed>  $context
 * @return array<string, string>
 */
function signedRpcHeaders(string $path, array $body, array $context = []): array
{
    return app(RpcSignature::class)->headers($path, json_encode($body), json_encode((object) $context));
}

/** @return array{contract: class-string, arguments: array<string, mixed>|object} */
function rpcBody(string $contract, array $arguments = []): array
{
    return ['contract' => $contract, 'arguments' => (object) $arguments];
}

/** iam runs in another process from here on: its calls leave through its host. */
function iamRunsElsewhere(): void
{
    config()->set('modulith.runs', 'analytics');
    app()->forgetInstance(ModuleRegistry::class);
    app()->forgetInstance(RpcTransportManager::class);
}

it('binds the contract to its RpcService, which reaches iam in this process when iam runs here', function () {
    Http::fake();

    expect(app(IamService::class))->toBeInstanceOf(IamRpcService::class)
        ->and(app(IamService::class)->findUser(1))->toBe(['id' => 1, 'name' => 'ada']);

    Http::assertNothingSent();
});

it('runs the local call in iam context, whichever module calls it', function () {
    $connection = $this->inModule('analytics', fn (): string => app(IamService::class)->defaultConnection());

    expect($connection)->toBe('iam')
        ->and($this->inModule('analytics', fn (): string => DB::getDefaultConnection()))->toBe('analytics');
});

it('runs DB:: queries and a DB::transaction() of the local call on iam database, called from analytics', function () {
    Schema::connection('iam')->create('iam_users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });
    DB::connection('iam')->table('iam_users')->insert(['id' => 1, 'name' => 'ada']);

    $count = $this->inModule('analytics', function (): int {
        app(IamService::class)->renameUser(1, 'grace');

        return app(IamService::class)->countUsers();
    });

    expect($count)->toBe(1)
        ->and(DB::connection('iam')->table('iam_users')->value('name'))->toBe('grace')
        ->and(Schema::connection('analytics')->hasTable('iam_users'))->toBeFalse()
        ->and(DB::connection('iam')->transactionLevel())->toBe(0);
});

it('only calls a method of a contract the module implements', function () {
    app(RpcTransport::class)->invoke(app(ModuleRegistry::class)->get('iam'), IamService::class, 'shutdown');
})->throws(ModuleException::class, 'answers no [Foundation\Iam\Contracts\IamService::shutdown()]');

it('rejects an unsigned call', function () {
    $this->postJson('/iam/rpc/findUser', rpcBody(IamService::class, ['id' => 1]))->assertForbidden();
});

it('answers a signed call through the module implementation', function () {
    $body = rpcBody(IamService::class, ['id' => 1]);

    $this->postJson('/iam/rpc/findUser', $body, signedRpcHeaders('/iam/rpc/findUser', $body))
        ->assertOk()
        ->assertExactJson(['id' => 1, 'name' => 'ada']);
});

it('answers a missing record with a 404, read back as null', function () {
    $body = rpcBody(IamService::class, ['id' => 9]);

    $this->postJson('/iam/rpc/findUser', $body, signedRpcHeaders('/iam/rpc/findUser', $body))->assertNotFound();
});

it('answers a method or a contract the module does not serve with a 400, not a failure', function () {
    foreach (['/iam/rpc/doesNotExist' => rpcBody(IamService::class), '/iam/rpc/findUser' => rpcBody('Foundation\Iam\Contracts\Unknown')] as $path => $body) {
        $this->postJson($path, $body, signedRpcHeaders($path, $body))
            ->assertStatus(400)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'Module [iam] answers no'));
    }
});

it('rejects a stale signature', function () {
    $timestamp = (string) (time() - 3600);
    $body = rpcBody(IamService::class, ['id' => 1]);

    $this->postJson('/iam/rpc/findUser', $body, [
        RpcSignature::TIMESTAMP_HEADER => $timestamp,
        RpcSignature::NONCE_HEADER => 'n-1',
        RpcSignature::CONTEXT_HEADER => '{}',
        RpcSignature::SIGNATURE_HEADER => app(RpcSignature::class)->sign($timestamp, 'n-1', '/iam/rpc/findUser', json_encode($body), '{}'),
    ])->assertForbidden();
});

it('rejects a signed call replayed', function () {
    $body = rpcBody(IamService::class, ['id' => 1]);
    $headers = signedRpcHeaders('/iam/rpc/findUser', $body);

    $this->postJson('/iam/rpc/findUser', $body, $headers)->assertOk();
    $this->postJson('/iam/rpc/findUser', $body, $headers)->assertForbidden();
});

it('restores the propagated context on the called side', function () {
    $body = rpcBody(IamService::class);

    $this->postJson('/iam/rpc/traceId', $body, signedRpcHeaders('/iam/rpc/traceId', $body, ['trace_id' => 'abc']))
        ->assertOk()->assertContent('"abc"');
});

it('rejects a context changed after signing', function () {
    $body = rpcBody(IamService::class);

    $this->postJson('/iam/rpc/traceId', $body, [
        ...signedRpcHeaders('/iam/rpc/traceId', $body, ['trace_id' => 'abc']),
        RpcSignature::CONTEXT_HEADER => json_encode(['trace_id' => 'forged']),
    ])->assertForbidden();
});

it('sends a signed POST naming the contract and the method, and decodes the answer', function () {
    config()->set('modulith.events.propagate', ['trace_id']);
    Context::add('trace_id', 'abc');
    Http::fake(['iam.test/*' => Http::response(['id' => 1, 'name' => 'ada'])]);

    $answer = app(HttpRpcTransport::class)->invoke(app(ModuleRegistry::class)->get('iam'), IamService::class, 'findUser', ['id' => 1]);

    expect($answer)->toBe(['id' => 1, 'name' => 'ada']);

    Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'http://iam.test/iam/rpc/findUser'
        && $request['contract'] === IamService::class
        && $request['arguments'] === ['id' => 1]
        && $request->header(RpcSignature::CONTEXT_HEADER)[0] === '{"trace_id":"abc"}'
        && app(RpcSignature::class)->verify(
            $request->header(RpcSignature::TIMESTAMP_HEADER)[0],
            $request->header(RpcSignature::NONCE_HEADER)[0],
            '/iam/rpc/findUser',
            $request->body(),
            $request->header(RpcSignature::CONTEXT_HEADER)[0],
            $request->header(RpcSignature::SIGNATURE_HEADER)[0],
        ));
});

it('reaches iam over HTTP once iam runs elsewhere', function () {
    iamRunsElsewhere();
    Http::fake(['iam.test/iam/rpc/findUser' => Http::response(['id' => 1, 'name' => 'ada'])]);

    expect(app(RpcTransport::class)->invoke(app(ModuleRegistry::class)->get('iam'), IamService::class, 'findUser', ['id' => 1]))
        ->toBe(['id' => 1, 'name' => 'ada']);

    Http::assertSentCount(1);
});

it('reads a missing remote record as null', function () {
    iamRunsElsewhere();
    Http::fake(['iam.test/*' => Http::response(null, 404)]);

    expect(app(RpcTransport::class)->invoke(app(ModuleRegistry::class)->get('iam'), IamService::class, 'findUser', ['id' => 9]))->toBeNull();
});

it('caches a remote answer for as long as the answer says, and forgets it on demand', function () {
    $service = app(TokenRpcService::class);

    expect($service->token(0))->toBeNull()
        ->and($service->token(60)['token'])->toBe('t2')
        ->and($service->token(60)['token'])->toBe('t2');

    $service->drop();

    expect($service->token(60)['token'])->toBe('t3');
});

it('keeps the raw answer and maps it on every read, dropping one that no longer maps', function () {
    $service = app(TokenRpcService::class);
    $service->shaped(fn (): array => ['user_id' => 1]);

    expect(Cache::get('iam:shaped'))->toBeNull()
        ->and($service->shaped(fn (): array => ['id' => 7]))->toBe(['id' => 7])
        ->and(Cache::get('iam:shaped'))->toBe(['id' => 7]);
});

it('keeps the answers in the store modulith.rpc.cache names, shared by the caller and the owner', function () {
    config()->set('cache.stores.rpc', ['driver' => 'array']);
    config()->set('modulith.rpc.cache', 'rpc');

    app(IamRpcService::class)->findUser(1);

    expect(Cache::store('rpc')->get('iam:user:1'))->toBe(['id' => 1, 'name' => 'ada'])
        ->and(Cache::get('iam:user:1'))->toBeNull();
});

it('routes a call to the transport its module host names, registered with extend()', function () {
    iamRunsElsewhere();
    config()->set('modulith.rpc.transports.grpc', ['driver' => 'grpc', 'port' => 50051]);
    config()->set('modulith.modules.iam.host', ['url' => 'grpc://iam', 'transport' => 'grpc']);

    app(RpcTransportManager::class)->extend('grpc', fn ($app, array $config): RpcTransport => new class($config) implements RpcTransport
    {
        /** @param array<string, mixed> $config */
        public function __construct(private array $config) {}

        public function invoke(Module $module, string $contract, string $method, array $arguments = []): mixed
        {
            return ['via' => 'grpc', 'port' => $this->config['port'], 'call' => "{$module->name}/".class_basename($contract)."::{$method}"];
        }
    });

    expect(app(RpcTransport::class)->invoke(app(ModuleRegistry::class)->get('iam'), IamService::class, 'findUser'))
        ->toBe(['via' => 'grpc', 'port' => 50051, 'call' => 'iam/IamService::findUser']);
});

it('fails loudly on an RPC transport nobody declared', function () {
    iamRunsElsewhere();
    config()->set('modulith.modules.iam.host', ['url' => 'x', 'transport' => 'amqp']);

    app(RpcTransport::class)->invoke(app(ModuleRegistry::class)->get('iam'), IamService::class, 'findUser');
})->throws(ConfigurationException::class, 'RPC transport [amqp] is not declared');
