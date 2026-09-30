<?php

declare(strict_types=1);

// The iam module's runtime connection and the owner one modulith:migrate targets.
return [
    'connections' => [
        'iam' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'iam_owner' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
    ],
];
