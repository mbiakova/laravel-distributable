<?php

declare(strict_types=1);

namespace Apps\Analytics\Reports;

use Distributable\Services\Modules\ModuleContext;
use Distributable\Services\Modules\ModuleRegistry;
use Microservices\Contracts\Colocation;

/** Analytics code that switches to a module by itself, as boundaries forbid for any module but its own. */
final readonly class EntersIam
{
    public function __construct(private ModuleContext $context, private ModuleRegistry $registry, private Colocation $colocation) {}

    public function directly(string $module): mixed
    {
        return $this->context->within($this->registry->get($module), static fn (): string => 'entered');
    }

    public function throughColocation(string $module): mixed
    {
        return $this->colocation->within($module, static fn (): string => 'entered');
    }
}
