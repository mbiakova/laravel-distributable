<?php

declare(strict_types=1);

namespace Distributable\Console\Migrations;

use Illuminate\Database\Console\Migrations\RefreshCommand as Base;

final class RefreshCommand extends Base
{
    use RunsForEachModule;

    public function __construct()
    {
        parent::__construct();
        $this->addModuleOption();
    }
}
