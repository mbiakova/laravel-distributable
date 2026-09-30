<?php

declare(strict_types=1);

namespace Modulith\Services\Modules;

use Illuminate\Contracts\Foundation\Application;

/**
 * What discovery found, written by modulith:cache to bootstrap/cache/modulith.php, like config:cache.
 *
 * @phpstan-type Cached array{
 *     modules: list<array{name: string, namespace: string, provider: string, path: string, hasDatabase: bool}>,
 *     shadows: array<string, list<class-string>>,
 *     sources: array<string, list<class-string>>,
 * }
 */
final class DiscoveryCache
{
    /** @var Cached|false|null */
    private array|false|null $loaded = null;

    public function __construct(private readonly Application $app) {}

    public function path(): string
    {
        return $this->app->bootstrapPath('cache/modulith.php');
    }

    /** @return Cached|null */
    public function load(): ?array
    {
        if ($this->loaded === null) {
            /** @var Cached|false $loaded */
            $loaded = is_file($this->path()) ? require $this->path() : false;
            $this->loaded = $loaded;
        }

        return $this->loaded === false ? null : $this->loaded;
    }

    /** @param Cached $data */
    public function write(array $data): void
    {
        file_put_contents($this->path(), '<?php return '.var_export($data, true).';'.PHP_EOL);
        $this->loaded = $data;
    }

    public function clear(): void
    {
        if (is_file($this->path())) {
            unlink($this->path());
        }

        $this->loaded = false;
    }
}
