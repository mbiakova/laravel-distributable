<?php

declare(strict_types=1);

namespace Apps\Iam\Listeners;

use Distributable\Tests\Support\SomethingHappened;
use Illuminate\Support\Facades\DB;

final class RecordEventConnection
{
    /** @var list<string> */
    public static array $seen = [];

    public function handle(SomethingHappened $event): void
    {
        self::$seen[] = 'handle:'.DB::getDefaultConnection();
    }

    public function remember(SomethingHappened $event): void
    {
        self::$seen[] = 'remember:'.DB::getDefaultConnection();
    }
}
