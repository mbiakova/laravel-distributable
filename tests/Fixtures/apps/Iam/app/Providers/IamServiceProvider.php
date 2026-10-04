<?php

declare(strict_types=1);

namespace Apps\Iam\Providers;

use Apps\Iam\Listeners\AfterCommitRecordEventConnection;
use Apps\Iam\Listeners\QueuedRecordEventConnection;
use Apps\Iam\Listeners\RecordEventConnection;
use Apps\Iam\Services\IamService;
use Distributable\Providers\ModuleServiceProvider;
use Distributable\Tests\Support\SomethingCommitted;
use Distributable\Tests\Support\SomethingHappened;
use Foundation\Iam\Contracts\IamService as Contract;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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

    protected function schedule(Schedule $schedule): void
    {
        $schedule->call(static fn () => Cache::put('scheduled-connection', DB::getDefaultConnection()))->everyMinute();
    }
}
