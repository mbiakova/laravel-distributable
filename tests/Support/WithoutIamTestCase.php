<?php

declare(strict_types=1);

namespace Distributable\Tests\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;

/** Same fixture tree, but RUN_MODULES excludes iam: its provider must not register. */
abstract class WithoutIamTestCase extends ModuleAppTestCase
{
    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app->make(Repository::class)->set('distributable.runs', 'gateway');
    }
}
