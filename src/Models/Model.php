<?php

declare(strict_types=1);

namespace Modulith\Models;

use Illuminate\Database\Eloquent\Model as BaseModel;
use Modulith\Traits\ResolvesModule;

abstract class Model extends BaseModel
{
    use ResolvesModule;

    protected $guarded = ['id'];

    /** Routes the model to its module's database connection, resolved from the class namespace. */
    public function getConnectionName(): ?string
    {
        return $this->module->connection();
    }

    /**
     * Prefixes the table with the module name (`{module}_{default}`), mirroring the per-module
     * connection resolution. An explicit `$table` wins — used for native framework tables and
     * any model whose class name already carries the module.
     */
    public function getTable(): string
    {
        if (isset($this->table)) {
            return $this->table;
        }

        return $this->module->name.'_'.parent::getTable();
    }
}
