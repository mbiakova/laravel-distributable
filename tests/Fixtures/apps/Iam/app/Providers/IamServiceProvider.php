<?php

declare(strict_types=1);

namespace Apps\Iam\Providers;

use Apps\Iam\Services\IamService;
use Foundation\Iam\Contracts\IamService as Contract;
use Modulith\Providers\ModuleServiceProvider;

final class IamServiceProvider extends ModuleServiceProvider
{
    protected array $services = [
        Contract::class => IamService::class,
    ];
}
