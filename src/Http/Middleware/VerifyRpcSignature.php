<?php

declare(strict_types=1);

namespace Modulith\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Modulith\Services\RpcSignature;
use Symfony\Component\HttpFoundation\Response;

/** Guards every module's routes/rpc.php: only a correctly signed, fresh call gets through. */
final readonly class VerifyRpcSignature
{
    public function __construct(private RpcSignature $signature) {}

    public function handle(Request $request, Closure $next): Response
    {
        $verified = $this->signature->verify(
            (string) $request->header(RpcSignature::TIMESTAMP_HEADER),
            '/'.ltrim($request->path(), '/'),
            $request->getContent(),
            (string) $request->header(RpcSignature::SIGNATURE_HEADER),
        );

        abort_unless($verified, 403, 'Invalid RPC signature.');

        /** @var array<string, mixed> $context */
        $context = json_decode((string) $request->header(RpcSignature::CONTEXT_HEADER, '{}'), true) ?: [];
        Context::add($context);

        return $next($request);
    }
}
