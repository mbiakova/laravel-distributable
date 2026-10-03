<?php

declare(strict_types=1);

namespace Modulith\Tests;

use Microservices\Providers\MicroservicesServiceProvider;
use Modulith\Providers\ModulithServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [MicroservicesServiceProvider::class, ModulithServiceProvider::class];
    }
}
