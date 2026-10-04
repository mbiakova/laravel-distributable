<?php

declare(strict_types=1);

namespace Distributable\Traits;

use Distributable\Services\Modules\ModuleRegistry;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;

/**
 * Put on a module's Eloquent model: it reads and writes its module's database whatever module the
 * running code is in. A class outside every module, or of a module without a database, keeps Laravel's rule.
 *
 * @mixin Model
 */
trait OnModuleConnection
{
    public function getConnectionName(): ?string
    {
        $module = Container::getInstance()->make(ModuleRegistry::class)->forClass(static::class);

        if ($module === null || ! $module->hasDatabase) {
            return parent::getConnectionName();
        }

        // db:seed sets the module's owner connection as the default: seeders write as the owner.
        $default = Container::getInstance()->make('config')->get('database.default');

        return $default === $module->ownerConnection() ? $default : $module->connection();
    }
}
