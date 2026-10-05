<?php

declare(strict_types=1);

namespace Distributable\Tests\Support;

use ArrayObject;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;

/** A package service whose provider loads only when the service is first asked for, as Laravel's mail and cache are. */
final class DeferredReportsProvider extends ServiceProvider implements DeferrableProvider
{
    public function register(): void
    {
        $this->app->singleton('reports', static fn (): ArrayObject => new ArrayObject);
    }

    /** @return list<string> */
    public function provides(): array
    {
        return ['reports'];
    }
}
