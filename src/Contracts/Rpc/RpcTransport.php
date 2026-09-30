<?php

declare(strict_types=1);

namespace Modulith\Contracts\Rpc;

use Modulith\Data\Module;

/** Carries a call to a module running in another process; the operation is data, not a method. */
interface RpcTransport
{
    /**
     * @param  array<string, mixed>  $payload
     * @return mixed the decoded answer, null when the module has no such record
     */
    public function invoke(Module $module, string $resource, string $operation, array $payload = []): mixed;
}
