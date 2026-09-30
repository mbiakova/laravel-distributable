<?php

declare(strict_types=1);

namespace Apps\Iam\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SyncDirectory implements ShouldQueue
{
    use Batchable;
    use Queueable;

    public function handle(): void {}
}
