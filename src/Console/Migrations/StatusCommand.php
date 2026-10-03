<?php

declare(strict_types=1);

namespace Distributable\Console\Migrations;

use Illuminate\Database\Console\Migrations\StatusCommand as Base;
use Illuminate\Database\Migrations\Migrator;

final class StatusCommand extends Base
{
    use RunsForEachModule;

    public function __construct(Migrator $migrator)
    {
        parent::__construct($migrator);
        $this->addModuleOption();
    }
}
