<?php

declare(strict_types=1);

namespace Modulith\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modulith\Exceptions\ModuleException;
use Modulith\Services\Modules\ModuleRegistry;
use Modulith\Services\Rpc\LocalServices;

/** The called side of HttpRpcTransport: POST {module}/rpc/{method}, already verified by the `rpc` group. */
final readonly class RpcController
{
    public function __construct(
        private ModuleRegistry $registry,
        private LocalServices $services,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        // The signed body as sent: input() would carry the global middleware's rewrites ('' becomes null).
        $body = (array) json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
        /** @var class-string $contract */
        $contract = (string) ($body['contract'] ?? '');
        /** @var array<string, mixed> $arguments */
        $arguments = (array) ($body['arguments'] ?? []);
        $module = $this->registry->get((string) $request->route('module'));
        $method = (string) $request->route('method');

        // The caller's mistake, not a failure here; 404 already means a null answer.
        if (! $this->services->answers($module, $contract, $method)) {
            return response()->json(['message' => ModuleException::unknownRpcMethod($module->name, $contract, $method)->getMessage()], 400);
        }

        $answer = $this->services->call($module, $contract, $method, $arguments);

        return $answer === null ? response()->json(null, 404) : response()->json($answer);
    }
}
