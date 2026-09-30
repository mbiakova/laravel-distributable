<?php

declare(strict_types=1);

use Modules\Iam\Contracts\IamService;
use Modules\Iam\Services\LocalIamService;
use Modules\Iam\Services\RemoteIamService;

return [
    'services' => [
        IamService::class => [
            'module' => 'iam',
            'local' => LocalIamService::class,
            'remote' => RemoteIamService::class,
        ],
    ],
];
