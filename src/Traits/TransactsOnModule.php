<?php

declare(strict_types=1);

namespace Modulith\Traits;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Runs a callback in a transaction on THIS class's module connection, not the framework
 * default — so multi-table writes in an Action/handler are atomic on the module's own
 * database (the framework default points elsewhere, and per module deployment may not even exist).
 */
trait TransactsOnModule
{
    use ResolvesModule;

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    protected function transaction(Closure $callback): mixed
    {
        return DB::connection($this->module->connection())->transaction($callback);
    }
}
