<?php

declare(strict_types=1);

namespace Distributable\Exceptions;

use RuntimeException;

/** A module that cannot be found, named, declared or resolved. */
final class ModuleException extends RuntimeException
{
    /** @param list<string> $known */
    public static function notFound(string $name, array $known): self
    {
        return new self(sprintf(
            'Unknown module [%s]. Known modules: %s.',
            $name,
            $known === [] ? '(none)' : implode(', ', $known),
        ));
    }

    public static function invalidName(string $name): self
    {
        return new self("Invalid module name [{$name}]: expected lowercase snake_case.");
    }

    public static function duplicateName(string $name): self
    {
        return new self("Duplicate module name [{$name}] in the module source.");
    }

    public static function notLocal(string $class, string $module): self
    {
        return new self("[{$class}] belongs to module [{$module}], which this process does not run (RUN_MODULES): go through its foundation contract or an event.");
    }

    public static function missingFolder(string $module, string $path): self
    {
        return new self("Module [{$module}] runs here (RUN_MODULES) but [{$path}] does not exist: was it purged from this image?");
    }

    /** A class the package needs to attach to a module lives outside every module namespace. */
    public static function outsideModule(string $class): self
    {
        return new self("Cannot resolve the module of [{$class}]: it is not inside a module namespace.");
    }
}
