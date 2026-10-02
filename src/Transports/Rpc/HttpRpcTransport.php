<?php

declare(strict_types=1);

namespace Modulith\Transports\Rpc;

use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Context;
use Modulith\Config\Rpc;
use Modulith\Config\Streamer;
use Modulith\Contracts\Rpc\RpcTransport;
use Modulith\Data\Module;
use Modulith\Services\Rpc\RpcSignature;

/** POST {host}/{module}/rpc/{method} with {contract, arguments}, signed, carrying the propagated context. */
final readonly class HttpRpcTransport implements RpcTransport
{
    public function __construct(
        private Http $http,
        private Rpc $rpc,
        private Streamer $streamer,
        private RpcSignature $signature,
    ) {}

    public function invoke(Module $module, string $contract, string $method, array $arguments = []): mixed
    {
        $path = "/{$module->name}/rpc/{$method}";
        $body = json_encode(['contract' => $contract, 'arguments' => (object) $arguments], JSON_THROW_ON_ERROR);
        $context = json_encode(Context::only($this->streamer->getPropagate()), JSON_THROW_ON_ERROR);

        $response = $this->http
            ->withHeaders(['Accept' => 'application/json', ...$this->signature->headers($path, $body, $context)])
            ->withBody($body, 'application/json')
            ->post(rtrim($this->rpc->getHost($module->name), '/').$path);

        if ($response->notFound()) {
            return null;
        }

        $response->throw();

        return $response->json();
    }
}
