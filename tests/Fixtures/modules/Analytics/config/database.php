<?php

declare(strict_types=1);

return [
    'connections' => [
        'analytics' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'analytics_owner' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
    ],
];
