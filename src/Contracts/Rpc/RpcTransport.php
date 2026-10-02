<?php

declare(strict_types=1);

namespace Modulith\Contracts\Rpc;

use Modulith\Data\Module;

/** Carries a call to a method of a foundation contract, answered by the module that implements it. */
interface RpcTransport
{
    /**
     * @param  class-string  $contract
     * @param  array<string, mixed>  $arguments  the method's arguments, by name
     * @return mixed the answer decoded as JSON would decode it; null when there is none
     */
    public function invoke(Module $module, string $contract, string $method, array $arguments = []): mixed;
}
