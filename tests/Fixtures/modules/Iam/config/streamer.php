<?php

declare(strict_types=1);

use Modules\Iam\Events\IamEvent;
use Modules\Iam\Handlers\OnUserRegistered;

return [
    'listen' => [
        IamEvent::UserRegistered->value => [OnUserRegistered::class],
    ],
];
