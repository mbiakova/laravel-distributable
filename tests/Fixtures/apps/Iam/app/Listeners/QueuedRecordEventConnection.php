<?php

declare(strict_types=1);

namespace Apps\Iam\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Modulith\Tests\Support\SomethingHappened;

final class QueuedRecordEventConnection implements ShouldQueue
{
    public function handle(SomethingHappened $event): void
    {
        RecordEventConnection::$seen[] = 'queued:'.DB::getDefaultConnection();
    }
}
