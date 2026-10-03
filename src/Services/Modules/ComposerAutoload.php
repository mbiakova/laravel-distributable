<?php

declare(strict_types=1);

namespace Distributable\Services\Modules;

use Distributable\Config\Modules;
use Distributable\Data\Module;
use stdClass;

/**
 * The PSR-4 entries composer.json may carry for the modules and the foundation. The application
 * never needs them: they are for the tools that read composer.json without booting it (an IDE).
 */
final readonly class ComposerAutoload
{
    public function __construct(private Modules $config) {}

    /** @return array<string, string> a module's code, factories and seeders; none when it lives outside the project */
    public function entriesOf(Module $module): array
    {
        $path = $this->relative($module->path());

        return $path === null ? [] : [
            $module->namespace.'\\' => $path.'/app/',
            $module->namespace.'\\Database\\Factories\\' => $path.'/database/factories/',
            $module->namespace.'\\Database\\Seeders\\' => $path.'/database/seeders/',
        ];
    }

    /** @return array<string, string> a module's tests, for autoload-dev */
    public function devEntriesOf(Module $module): array
    {
        $path = $this->relative($module->path());

        return $path === null ? [] : [$module->namespace.'\\Tests\\' => $path.'/tests/'];
    }

    /** @return array<string, string> */
    public function foundationEntries(): array
    {
        $path = $this->relative($this->config->getFoundationPath());

        return $path === null ? [] : [$this->config->getFoundationNamespace().'\\' => $path.'/'];
    }

    /**
     * @param  array<string, string>  $entries  for autoload
     * @param  array<string, string>  $dev  for autoload-dev
     * @return bool whether composer.json was changed
     */
    public function add(array $entries, array $dev = []): bool
    {
        $composer = $this->read();

        if ($composer === null) {
            return false;
        }

        $before = json_encode($composer);

        foreach (['autoload' => $entries, 'autoload-dev' => $dev] as $section => $added) {
            foreach ($added as $namespace => $path) {
                $composer->{$section} ??= new stdClass;
                $composer->{$section}->{'psr-4'} ??= new stdClass;
                $composer->{$section}->{'psr-4'}->{$namespace} = $path;
            }
        }

        return $this->write($composer, $before);
    }

    /**
     * @param  list<string>  $namespaces  removed from autoload and from autoload-dev
     * @return bool whether composer.json was changed
     */
    public function remove(array $namespaces): bool
    {
        $composer = $this->read();

        if ($composer === null) {
            return false;
        }

        $before = json_encode($composer);

        foreach (['autoload', 'autoload-dev'] as $section) {
            foreach ($namespaces as $namespace) {
                unset($composer->{$section}->{'psr-4'}->{$namespace});
            }
        }

        return $this->write($composer, $before);
    }

    private function write(stdClass $composer, string|false $before): bool
    {
        if (json_encode($composer) === $before) {
            return false;
        }

        file_put_contents(
            base_path('composer.json'),
            json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n",
        );

        return true;
    }

    /** @return array<string, array{declared: string, expected: string}> the entries composer.json maps somewhere else than the module lives */
    public function stale(Module $module): array
    {
        $declared = (array) ($this->read()->autoload->{'psr-4'} ?? []);
        $stale = [];

        foreach ($this->entriesOf($module) as $namespace => $expected) {
            if (isset($declared[$namespace]) && $declared[$namespace] !== $expected) {
                $stale[$namespace] = ['declared' => (string) $declared[$namespace], 'expected' => $expected];
            }
        }

        return $stale;
    }

    private function read(): ?stdClass
    {
        $file = base_path('composer.json');
        $composer = is_file($file) ? json_decode((string) file_get_contents($file)) : null;

        return $composer instanceof stdClass ? $composer : null;
    }

    private function relative(string $path): ?string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? rtrim(substr($path, strlen($base)), DIRECTORY_SEPARATOR) : null;
    }
}
