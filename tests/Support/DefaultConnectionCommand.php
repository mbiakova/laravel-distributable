<?php

declare(strict_types=1);

namespace Modulith\Tests\Support;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** A command no module owns, as a framework or third-party package command is. */
final class DefaultConnectionCommand extends Command
{
    protected $signature = 'probe:connection';

    public function handle(): int
    {
        $this->line(DB::getDefaultConnection());

        return self::SUCCESS;
    }
}
