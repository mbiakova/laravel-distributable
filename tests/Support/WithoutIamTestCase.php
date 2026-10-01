<?php

declare(strict_types=1);

namespace Modulith\Tests\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;

/** Same fixture tree, but MODULITH_RUNS excludes iam: its provider must not register. */
abstract class WithoutIamTestCase extends ModuleAppTestCase
{
    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app->make(Repository::class)->set('modulith.runs', 'gateway');
    }
}
