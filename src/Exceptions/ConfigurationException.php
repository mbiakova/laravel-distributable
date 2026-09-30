<?php

declare(strict_types=1);

namespace Modulith\Exceptions;

use RuntimeException;

/** A setting or a declared class the package cannot work with. */
final class ConfigurationException extends RuntimeException
{
    public static function invalidSource(string $class, string $contract): self
    {
        return new self("modulith.source [{$class}] must implement {$contract}.");
    }

    public static function invalidHandler(string $class, string $contract): self
    {
        return new self("Event handler [{$class}] must implement {$contract}.");
    }

    public static function missingRpcHost(string $module): self
    {
        return new self("No RPC host configured for module [{$module}]: set rpc.hosts.{$module}.");
    }
}
