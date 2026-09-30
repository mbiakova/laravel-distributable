<?php

declare(strict_types=1);

namespace Apps\Iam\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

final class RecordConnection implements ShouldQueue
{
    use Queueable;

    public static ?string $seen = null;

    public function handle(): void
    {
        self::$seen = DB::getDefaultConnection();
    }
}
