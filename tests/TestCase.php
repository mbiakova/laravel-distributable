<?php

declare(strict_types=1);

namespace Distributable\Tests;

use Distributable\Providers\DistributableServiceProvider;
use Microservices\Providers\MicroservicesServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [MicroservicesServiceProvider::class, DistributableServiceProvider::class];
    }
}
