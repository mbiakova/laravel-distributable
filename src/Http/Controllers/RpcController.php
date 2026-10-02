<?php

declare(strict_types=1);

namespace Modulith\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        $answer = $this->services->call($module, $contract, (string) $request->route('method'), $arguments);

        return $answer === null ? response()->json(null, 404) : response()->json($answer);
    }
}
