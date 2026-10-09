<?php

declare(strict_types=1);

namespace Distributable\Testing;

use Distributable\Config\Modules;
use Distributable\Data\Module;
use Distributable\Services\Modules\ModuleRegistry;
use Illuminate\Support\Str;
use Microservices\Services\Shadows\ShadowRegistry;
use PhpToken;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/** A module names only itself and the foundation, never another module's connection or tables; the foundation and the application name no module. */
final readonly class Boundaries
{
    public function __construct(
        private ModuleRegistry $registry,
        private Modules $config,
        private ShadowRegistry $shadows,
    ) {}

    /** @return list<string> one "{file}: {name}" per reference crossing a boundary */
    public function violations(): array
    {
        $modules = $this->registry->all();
        $violations = [];

        foreach ($modules as $module) {
            $others = array_values(array_filter($modules, static fn (Module $other): bool => $other->name !== $module->name));
            // A copy the module keeps may bear its source's name: that name is the module's own table.
            $copies = array_map(static fn (string $shadow): string => (new $shadow)->getTable(), $this->shadows->localShadows($module->name));
            $foreign = array_diff(array_merge(...array_map(fn (Module $other): array => $this->storageOf($other), $others)), $copies);

            foreach ($this->references($module->path()) as $file => $names) {
                foreach ($names as $name) {
                    if ($this->startsWithAny($name, array_map(static fn (Module $other): string => $other->namespace, $others))) {
                        $violations[] = "{$file}: {$name}";
                    }
                }
            }

            foreach ($this->strings($module->path()) as $file => $strings) {
                foreach (array_intersect($strings, $foreign) as $string) {
                    $violations[] = "{$file}: '{$string}'";
                }
            }
        }

        $modulePaths = array_map(static fn (Module $module): string => $module->path().DIRECTORY_SEPARATOR, $modules);

        foreach ([$this->config->getFoundationPath(), app_path(), base_path('routes'), config_path()] as $outside) {
            foreach ($this->references($outside) as $file => $names) {
                if (Str::startsWith($file, $modulePaths)) {
                    continue;
                }

                foreach ($names as $name) {
                    if ($this->startsWithAny($name, [$this->config->getModulesNamespace()])) {
                        $violations[] = "{$file}: {$name}";
                    }
                }
            }
        }

        return array_values(array_unique($violations));
    }

    /** @return list<string> the module's connections and every table its migrations create */
    private function storageOf(Module $module): array
    {
        $tables = [];

        foreach (glob($module->path().'/database/migrations/*.php') ?: [] as $migration) {
            preg_match_all('/Schema::create\(\s*[\'"]([^\'"]+)[\'"]/', (string) file_get_contents($migration), $matches);
            array_push($tables, ...$matches[1]);
        }

        return [$module->connection(), $module->ownerConnection(), ...$tables];
    }

    /** @return array<string, list<string>> the qualified names each PHP file under $path references */
    private function references(string $path): array
    {
        return $this->tokens($path, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], static fn (string $text): string => ltrim($text, '\\'));
    }

    /** @return array<string, list<string>> the plain string literals of each PHP file under $path */
    private function strings(string $path): array
    {
        return $this->tokens($path, [T_CONSTANT_ENCAPSED_STRING], static fn (string $text): string => substr($text, 1, -1));
    }

    /**
     * @param  list<int>  $kinds
     * @param  callable(string): string  $value
     * @return array<string, list<string>>
     */
    private function tokens(string $path, array $kinds, callable $value): array
    {
        if (! is_dir($path)) {
            return [];
        }

        $found = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            foreach (PhpToken::tokenize((string) file_get_contents($file->getPathname())) as $token) {
                if ($token->is($kinds)) {
                    $found[$file->getPathname()][] = $value($token->text);
                }
            }
        }

        return $found;
    }

    /** @param list<string> $namespaces */
    private function startsWithAny(string $name, array $namespaces): bool
    {
        foreach ($namespaces as $namespace) {
            if ($name === $namespace || str_starts_with($name, $namespace.'\\')) {
                return true;
            }
        }

        return false;
    }
}
