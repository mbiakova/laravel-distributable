<?php

declare(strict_types=1);

namespace Distributable\Console\Migrations;

use Illuminate\Database\Console\Migrations\FreshCommand as Base;
use Illuminate\Database\Migrations\Migrator;

final class FreshCommand extends Base
{
    use RunsForEachModule;

    public function __construct(Migrator $migrator)
    {
        parent::__construct($migrator);
        $this->addModuleOption();
    }
}
