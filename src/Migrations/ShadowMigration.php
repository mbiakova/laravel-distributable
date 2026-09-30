<?php

declare(strict_types=1);

namespace Modulith\Migrations;

use Illuminate\Database\Migrations\Migration;

/**
 * A migration the owner of a source table publishes in its database/shadows/: `migrate` runs it
 * in the database of every module keeping a copy, on a table named {keeper}_{source}.
 */
abstract class ShadowMigration extends Migration
{
    /** The module whose database the migration currently runs in, set by the migrate commands. */
    public static string $keeper = '';

    /** The source table the copy mirrors. */
    abstract protected function source(): string;

    protected function table(): string
    {
        return static::$keeper.'_'.$this->source();
    }
}
