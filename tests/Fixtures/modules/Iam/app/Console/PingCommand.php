<?php

declare(strict_types=1);

namespace Modules\Iam\Console;

use Illuminate\Console\Command;

final class PingCommand extends Command
{
    protected $signature = 'iam:ping';

    public function handle(): int
    {
        $this->line('pong');

        return self::SUCCESS;
    }
}
