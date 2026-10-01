<?php

declare(strict_types=1);

use Apps\Iam\Events\IamEvent;
use Apps\Iam\Handlers\OnUserRegistered;

return [
    'events' => [
        'streams' => [
            'audit' => ['driver' => 'array'],
        ],

        'listen' => [
            IamEvent::UserRegistered->value => [OnUserRegistered::class],
        ],
    ],
];
