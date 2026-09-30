<?php

declare(strict_types=1);

namespace Modulith\Console\Migrations;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Console\Migrations\MigrateCommand as Base;
use Illuminate\Database\Migrations\Migrator;

final class MigrateCommand extends Base
{
    use RunsForEachModule;

    public function __construct(Migrator $migrator, Dispatcher $dispatcher)
    {
        parent::__construct($migrator, $dispatcher);
        $this->addModuleOption();
    }
}
