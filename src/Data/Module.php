<?php

declare(strict_types=1);

namespace Distributable\Data;

use Distributable\Exceptions\ModuleException;
use Illuminate\Support\Str;

/**
 * Topology-independent descriptor of one module: identity and conventions only.
 * Whether the module is booted by this process is the registry's knowledge.
 */
final readonly class Module
{
    public function __construct(
        public string $name,
        public string $namespace,
        public string $provider,
        private string $path,
        public bool $hasDatabase,
    ) {}

    /** Every convention derives from the name; a module has a database once a connection named after it exists, in its config/database.php or the root one. */
    public static function fromName(string $name, string $modulesNamespace, string $modulesPath = 'apps'): self
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
            throw ModuleException::invalidName($name);
        }

        $studly = Str::studly($name);
        $namespace = $modulesNamespace.'\\'.$studly;
        $path = $modulesPath.'/'.$studly;
        $absolute = str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);

        return new self(
            name: $name,
            namespace: $namespace,
            provider: $namespace.'\\Providers\\'.$studly.'ServiceProvider',
            path: $path,
            hasDatabase: is_file($absolute.'/config/database.php') || config("database.connections.{$name}") !== null,
        );
    }

    /** @return array{name: string, namespace: string, provider: string, path: string, hasDatabase: bool} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'namespace' => $this->namespace,
            'provider' => $this->provider,
            'path' => $this->path,
            'hasDatabase' => $this->hasDatabase,
        ];
    }

    /** @param array{name: string, namespace: string, provider: string, path: string, hasDatabase: bool} $data */
    public static function fromArray(array $data): self
    {
        return new self(...$data);
    }

    /** Stored relative to the project root so a compiled registry stays portable across hosts. */
    public function path(): string
    {
        return str_starts_with($this->path, DIRECTORY_SEPARATOR) ? $this->path : base_path($this->path);
    }

    /** The PSR-4 root of the module's classes: {module}/app maps to its namespace. */
    public function classPath(): string
    {
        return $this->path().'/app';
    }

    public function connection(): string
    {
        return $this->name;
    }

    /**
     * Owner-role connection: the role that owns the tables and may run DDL, used for migrations.
     * The runtime connection above stays least-privileged — and on PostgreSQL, subject to the
     * row-level security policies the owner installs.
     */
    public function ownerConnection(): string
    {
        return $this->name.'_owner';
    }
}
