<?php

declare(strict_types=1);

// The iam module's runtime connection and the owner one the migrate commands target.
return [
    'connections' => [
        'iam' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'iam_owner' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
    ],
];
