<?php

declare(strict_types=1);

namespace Modulith\Transports;

use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Context;
use Modulith\Config\Rpc;
use Modulith\Config\Streamer;
use Modulith\Contracts\RpcTransport;
use Modulith\Data\Module;
use Modulith\Services\RpcSignature;

/** POST {host}/{module}/rpc/v1/{resource}/{operation}, signed, carrying the propagated context. */
final readonly class HttpRpcTransport implements RpcTransport
{
    public function __construct(
        private Http $http,
        private Rpc $rpc,
        private Streamer $streamer,
        private RpcSignature $signature,
    ) {}

    public function invoke(Module $module, string $resource, string $operation, array $payload = []): mixed
    {
        $path = "/{$module->name}/rpc/v1/{$resource}/{$operation}";
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();

        $response = $this->http
            ->withHeaders([
                'Accept' => 'application/json',
                RpcSignature::TIMESTAMP_HEADER => $timestamp,
                RpcSignature::SIGNATURE_HEADER => $this->signature->sign($timestamp, $path, $body),
                RpcSignature::CONTEXT_HEADER => json_encode(Context::only($this->streamer->getPropagate()), JSON_THROW_ON_ERROR),
            ])
            ->withBody($body, 'application/json')
            ->post(rtrim($this->rpc->getHost($module->name), '/').$path);

        if ($response->notFound()) {
            return null;
        }

        $response->throw();

        return $response->json();
    }
}
