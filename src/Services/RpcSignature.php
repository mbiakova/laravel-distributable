<?php

declare(strict_types=1);

namespace Modulith\Services;

use Modulith\Config\Rpc;

/** Signs and checks calls between modules: an HMAC over the timestamp, the path and the body. */
final readonly class RpcSignature
{
    public const string TIMESTAMP_HEADER = 'X-Modulith-Timestamp';

    public const string SIGNATURE_HEADER = 'X-Modulith-Signature';

    public const string CONTEXT_HEADER = 'X-Modulith-Context';

    public function __construct(private Rpc $config) {}

    public function sign(string $timestamp, string $path, string $body): string
    {
        return hash_hmac('sha256', $timestamp."\n".$path."\n".$body, $this->config->getSecret());
    }

    public function verify(string $timestamp, string $path, string $body, string $signature): bool
    {
        return $this->config->getSecret() !== ''
            && $timestamp !== ''
            && abs(time() - (int) $timestamp) <= $this->config->getSignatureTtl()
            && hash_equals($this->sign($timestamp, $path, $body), $signature);
    }
}
