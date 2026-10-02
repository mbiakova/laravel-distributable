<?php

declare(strict_types=1);

namespace Modulith\Console;

use Illuminate\Console\Command;
use Illuminate\Console\GeneratorCommand;
use Illuminate\Database\Console\Migrations\MigrateMakeCommand;
use Illuminate\Foundation\Console\ProviderMakeCommand;
use Symfony\Component\Console\Input\InputOption;

/** Every Artisan command takes --module: a generator writes in that module, any other command runs in its context. */
final class ModuleOption
{
    public static function addTo(Command $command): void
    {
        // A module has one provider, registered by the package: make:provider would add another to bootstrap/providers.php.
        if ($command instanceof ProviderMakeCommand || $command->getDefinition()->hasOption('module')) {
            return;
        }

        $command->addOption('module', null, InputOption::VALUE_REQUIRED, $command instanceof GeneratorCommand || $command instanceof MigrateMakeCommand
            ? 'Generate in this module, under its namespace'
            : 'Run in the context of this module');
    }
}
