<?php

declare(strict_types=1);

namespace Apps\Iam\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ConnectionCommand extends Command
{
    protected $signature = 'iam:connection';

    public function handle(): int
    {
        $this->line(DB::getDefaultConnection());

        return self::SUCCESS;
    }
}
