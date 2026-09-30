<?php

declare(strict_types=1);

namespace Modulith\Testing;

use Modulith\Config\Modules;
use Modulith\Data\Module;
use Modulith\Services\Modules\ModuleRegistry;
use PhpToken;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/** A module names only itself and the foundation; the foundation names no module. */
final readonly class Boundaries
{
    public function __construct(
        private ModuleRegistry $registry,
        private Modules $config,
    ) {}

    /** @return list<string> one "{file}: {name}" per reference crossing a boundary */
    public function violations(): array
    {
        $modules = $this->registry->all();
        $violations = [];

        foreach ($modules as $module) {
            $others = array_values(array_filter($modules, static fn (Module $other): bool => $other->name !== $module->name));

            foreach ($this->references($module->path()) as $file => $names) {
                foreach ($names as $name) {
                    if ($this->startsWithAny($name, array_map(static fn (Module $other): string => $other->namespace, $others))) {
                        $violations[] = "{$file}: {$name}";
                    }
                }
            }
        }

        foreach ($this->references($this->config->getFoundationPath()) as $file => $names) {
            foreach ($names as $name) {
                if ($this->startsWithAny($name, [$this->config->getModulesNamespace()])) {
                    $violations[] = "{$file}: {$name}";
                }
            }
        }

        return $violations;
    }

    /** @return array<string, list<string>> the qualified names each PHP file under $path references */
    private function references(string $path): array
    {
        if (! is_dir($path)) {
            return [];
        }

        $references = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            foreach (PhpToken::tokenize((string) file_get_contents($file->getPathname())) as $token) {
                if ($token->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                    $references[$file->getPathname()][] = ltrim($token->text, '\\');
                }
            }
        }

        return $references;
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
