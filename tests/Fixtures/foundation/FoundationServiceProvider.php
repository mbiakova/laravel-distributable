<?php

declare(strict_types=1);

namespace Foundation;

use Foundation\Iam\Contracts\IamService;
use Foundation\Iam\Services\IamRpcService;
use Modulith\Providers\FoundationServiceProvider as BaseServiceProvider;

final class FoundationServiceProvider extends BaseServiceProvider
{
    protected array $rpc = [
        IamService::class => IamRpcService::class,
    ];
}
