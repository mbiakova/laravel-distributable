<?php

declare(strict_types=1);

namespace Modulith\Services\Rpc;

use Illuminate\Container\Container;
use Modulith\Data\Module;
use Modulith\Exceptions\ModuleException;
use Modulith\Services\Modules\ModuleContext;

/**
 * The implementation each local module gives its foundation contracts (its provider's $services).
 * Every transport ends here, so a call behaves the same whether it came from this process or another.
 */
final class LocalServices
{
    /** @var array<class-string, array{module: Module, implementation: class-string}> */
    private array $services = [];

    public function __construct(private readonly ModuleContext $context) {}

    /**
     * @param  class-string  $contract
     * @param  class-string  $implementation
     */
    public function add(Module $module, string $contract, string $implementation): void
    {
        $this->services[$contract] = ['module' => $module, 'implementation' => $implementation];
    }

    /** Only a method of a contract the module declares in its provider's $services can be called. */
    public function answers(Module $module, string $contract, string $method): bool
    {
        return ($this->services[$contract]['module'] ?? null)?->name === $module->name && method_exists($contract, $method);
    }

    /**
     * Runs a method of $contract in its module's context and returns the answer as JSON decodes it,
     * the shape a remote caller receives.
     *
     * @param  class-string  $contract
     * @param  array<string, mixed>  $arguments
     */
    public function call(Module $module, string $contract, string $method, array $arguments = []): mixed
    {
        if (! $this->answers($module, $contract, $method)) {
            throw ModuleException::unknownRpcMethod($module->name, $contract, $method);
        }

        $service = $this->services[$contract];

        $answer = $this->context->within(
            $module,
            fn (): mixed => Container::getInstance()->make($service['implementation'])->{$method}(...$arguments),
        );

        return json_decode(json_encode($answer, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }
}
