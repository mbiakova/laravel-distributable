<?php

declare(strict_types=1);

namespace Apps\Iam\Listeners;

use Distributable\Tests\Support\SomethingCommitted;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\DB;

final class AfterCommitRecordEventConnection implements ShouldHandleEventsAfterCommit
{
    public function handle(SomethingCommitted $event): void
    {
        RecordEventConnection::$seen[] = 'after-commit:'.DB::getDefaultConnection();
    }
}
