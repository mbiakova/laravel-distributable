<?php

declare(strict_types=1);

namespace Modulith\Services;

use Illuminate\Support\Str;
use Modulith\Contracts\Source;
use Modulith\Data\Module;

/**
 * Discovers modules from the application tree: a directory of {modules_path} is a module when
 * it carries a modulith.php manifest. The manifest only marks it; everything else is convention.
 */
final class ManifestSource implements Source
{
    public const string MANIFEST = 'modulith.php';

    public function __construct(
        private readonly string $modulesPath,
        private readonly string $modulesNamespace,
    ) {}

    /** @return list<Module> */
    public function modules(): array
    {
        $root = str_starts_with($this->modulesPath, DIRECTORY_SEPARATOR) ? $this->modulesPath : base_path($this->modulesPath);

        $modules = [];

        foreach (glob($root.'/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $manifest = $directory.'/'.self::MANIFEST;

            if (! is_file($manifest)) {
                continue;
            }

            $modules[] = Module::fromName(Str::snake(basename($directory)), $this->modulesNamespace, $this->modulesPath);
        }

        return $modules;
    }
}
