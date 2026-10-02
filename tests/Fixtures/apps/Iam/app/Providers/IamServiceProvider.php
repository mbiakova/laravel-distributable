<?php

declare(strict_types=1);

namespace Apps\Iam\Providers;

use Apps\Iam\Listeners\AfterCommitRecordEventConnection;
use Apps\Iam\Listeners\QueuedRecordEventConnection;
use Apps\Iam\Listeners\RecordEventConnection;
use Apps\Iam\Services\IamService;
use Foundation\Iam\Contracts\IamService as Contract;
use Modulith\Providers\ModuleServiceProvider;
use Modulith\Tests\Support\SomethingCommitted;
use Modulith\Tests\Support\SomethingHappened;

final class IamServiceProvider extends ModuleServiceProvider
{
    protected array $services = [
        Contract::class => IamService::class,
    ];

    protected array $listen = [
        SomethingHappened::class => [
            RecordEventConnection::class,
            RecordEventConnection::class.'@remember',
            QueuedRecordEventConnection::class,
        ],
        SomethingCommitted::class => [AfterCommitRecordEventConnection::class],
    ];
}
