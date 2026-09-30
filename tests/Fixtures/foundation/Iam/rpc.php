<?php

declare(strict_types=1);

use Foundation\Iam\Contracts\IamService;
use Foundation\Iam\Services\IamRpcService;

return [
    IamService::class => IamRpcService::class,
];
