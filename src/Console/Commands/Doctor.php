<?php

declare(strict_types=1);

namespace Modulith\Console\Commands;

use Illuminate\Console\Command;
use Modulith\Config\Rpc;
use Modulith\Services\Modules\ModuleRegistry;
use Modulith\Testing\Boundaries;

final class Doctor extends Command
{
    protected $signature = 'modulith:doctor';

    protected $description = 'Check that every module can run: provider, connections, remote hosts, boundaries.';

    public function handle(ModuleRegistry $registry, Rpc $rpc, Boundaries $boundaries): int
    {
        $problems = [];

        foreach ($registry->all() as $module) {
            if (! class_exists($module->provider)) {
                $problems[] = "[{$module->name}] provider {$module->provider} does not exist.";
            }

            foreach ($module->hasDatabase && $registry->isLocal($module->name) ? [$module->connection(), $module->ownerConnection()] : [] as $connection) {
                if (config("database.connections.{$connection}") === null) {
                    $problems[] = "[{$module->name}] connection [{$connection}] is not declared.";
                }
            }
        }

        foreach ($rpc->getServices() as $contract => $service) {
            if (! $registry->isLocal($service['module']) && ! $rpc->hasHost($service['module'])) {
                $problems[] = "[{$service['module']}] runs elsewhere and serves {$contract}, but rpc.hosts has no entry for it.";
            }
        }

        if ($rpc->getServices() !== [] && $rpc->getSecret() === '') {
            $problems[] = 'Modules serve RPC contracts but rpc.secret is empty: set MODULITH_RPC_SECRET or APP_KEY.';
        }

        foreach ($boundaries->violations() as $violation) {
            $problems[] = "Boundary crossed: {$violation}";
        }

        foreach ($problems as $problem) {
            $this->components->error($problem);
        }

        if ($problems === []) {
            $this->components->info('Every module can run.');
        }

        return $problems === [] ? self::SUCCESS : self::FAILURE;
    }
}
