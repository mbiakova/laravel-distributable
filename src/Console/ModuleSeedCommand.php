<?php

declare(strict_types=1);

namespace Distributable\Console;

use Distributable\Data\Module;
use Distributable\Services\Modules\ModuleContext;
use Illuminate\Database\Console\Seeds\SeedCommand;

/** db:seed in a module (--module, or a migrate run of that module) runs the module's own seeders, from {module}/database/seeders. */
final class ModuleSeedCommand extends SeedCommand
{
    private const string ROOT_SEEDER = 'Database\\Seeders\\DatabaseSeeder';

    public function handle(): int
    {
        $module = $this->module();

        if ($module !== null && $this->requested() === self::ROOT_SEEDER && ! class_exists($this->seederOf($module))) {
            $this->components->info("Module [{$module->name}] has no DatabaseSeeder: nothing to seed.");

            return self::SUCCESS;
        }

        return (int) parent::handle();
    }

    protected function getSeeder()
    {
        $module = $this->module();

        return $module === null
            ? parent::getSeeder()
            : $this->laravel->make($this->seederOf($module))->setContainer($this->laravel)->setCommand($this);
    }

    private function seederOf(Module $module): string
    {
        $class = $this->requested();

        return match (true) {
            $class === self::ROOT_SEEDER => $module->namespace.'\\'.self::ROOT_SEEDER,
            ! str_contains($class, '\\') => $module->namespace.'\\Database\\Seeders\\'.$class,
            default => $class,
        };
    }

    private function requested(): string
    {
        return (string) ($this->input->getArgument('class') ?? $this->input->getOption('class'));
    }

    private function module(): ?Module
    {
        return $this->laravel->make(ModuleContext::class)->current();
    }
}
