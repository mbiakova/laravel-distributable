<?php

declare(strict_types=1);

namespace Apps\Iam\Http\Controllers;

use Illuminate\Support\Facades\DB;

final class ConnectionController
{
    public function __invoke(): string
    {
        return DB::getDefaultConnection();
    }
}
