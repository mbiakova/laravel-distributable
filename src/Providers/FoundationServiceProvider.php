<?php

declare(strict_types=1);

namespace Modulith\Providers;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Modulith\Config\Modules;
use Modulith\Services\Modules\ModuleRegistry;
use Modulith\Services\Rpc\RpcService;

/**
 * Base of the application's {Foundation}\FoundationServiceProvider: it maps each contract to the
 * RpcService that calls its module over the network, bound only when no local module bound it.
 */
abstract class FoundationServiceProvider extends BaseServiceProvider
{
    /** @var array<class-string, class-string<RpcService>> */
    protected array $rpc = [];

    public function boot(): void
    {
        $registry = $this->app->make(ModuleRegistry::class);
        $namespace = $this->app->make(Modules::class)->getFoundationNamespace();
        $services = [];

        foreach ($this->rpc as $contract => $service) {
            if (! $this->app->bound($contract)) {
                $this->app->bind($contract, $service);
            }

            $services[$contract] = ['module' => $registry->forFoundationClass($contract, $namespace)?->name, 'rpc' => $service];
        }

        $this->app['config']->set('rpc.services', [...$this->app['config']->get('rpc.services', []), ...$services]);
    }
}
