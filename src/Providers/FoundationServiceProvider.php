<?php

declare(strict_types=1);

namespace Modulith\Providers;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Modulith\Config\Modules;
use Modulith\Contracts\Stream\Versioned;
use Modulith\Services\Modules\ModuleRegistry;
use Modulith\Services\Rpc\RpcService;
use Modulith\Services\Rpc\RpcServices;
use Modulith\Services\Stream\PayloadVersions;

/**
 * Base of the application's {Foundation}\FoundationServiceProvider: each contract is bound to the
 * RpcService that calls its module, which reaches the module in this process or over the network.
 */
abstract class FoundationServiceProvider extends BaseServiceProvider
{
    /** @var array<class-string, class-string<RpcService>> */
    protected array $rpc = [];

    /** @var array<string, class-string<Versioned>> event name => the payload class that declares its versions */
    protected array $payloads = [];

    public function boot(): void
    {
        $this->app->make(PayloadVersions::class)->add($this->payloads);

        $registry = $this->app->make(ModuleRegistry::class);
        $namespace = $this->app->make(Modules::class)->getFoundationNamespace();
        $services = [];

        foreach ($this->rpc as $contract => $service) {
            $this->app->bind($contract, $service);

            $services[$contract] = ['module' => $registry->forFoundationClass($contract, $namespace)?->name, 'rpc' => $service];
        }

        $this->app->make(RpcServices::class)->add($services);
    }
}
