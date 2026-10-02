<?php

declare(strict_types=1);

namespace Modulith\Services\Stream;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Date;
use Modulith\Config\Streamer;
use Modulith\Contracts\Stream\Handler;
use Modulith\Contracts\Stream\Idempotent;
use Modulith\Contracts\Stream\RedeliversEnvelopes;
use Modulith\Data\Envelope;
use Modulith\Data\Module;
use Modulith\Events\ShadowChanged;
use Modulith\Events\ShadowWanted;
use Modulith\Exceptions\ConfigurationException;
use Modulith\Exceptions\ModuleException;
use Modulith\Handlers\AnnounceShadowSource;
use Modulith\Handlers\SyncShadows;
use Modulith\Services\Modules\ModuleContext;
use Modulith\Services\Modules\ModuleRegistry;

/**
 * Runs the local handlers of an envelope. Every transport ends here — in-process delivery and a
 * broker consumer take exactly the same path, so a handler behaves identically in mono and in
 * micro.
 *
 * When delivery is at-least-once, each run is guarded: the mark in `event_consumptions` and the
 * handler's own writes share one transaction on the consuming module's database, so a replay is
 * a no-op and a failed handler leaves no mark behind. Handlers become idempotent by
 * construction — their author writes nothing. The guard covers what a handler writes to its
 * database; effects outside it (mail, third-party calls) stay at-least-once.
 */
final class Dispatcher
{
    public function __construct(
        private readonly Container $container,
        private readonly Streamer $config,
        private readonly ModuleRegistry $registry,
        private readonly ModuleContext $context,
        private readonly DatabaseManager $db,
        private readonly TransportManager $transports,
        private readonly PayloadVersions $versions,
    ) {}

    /** With a $consumer, only its handlers run: a process running several modules has one consumer per module. */
    public function dispatch(Envelope $envelope, ?Module $consumer = null): void
    {
        $guarded = $this->guarded($envelope->stream);

        foreach ($this->handlersFor($envelope->name) as $class) {
            $owner = $this->registry->forClass($class);

            if ($consumer !== null && $owner !== null && $owner->name !== $consumer->name) {
                continue;
            }

            $guarded && ! is_subclass_of($class, Idempotent::class)
                ? $this->runGuarded($class, $envelope)
                : $this->run($class, $envelope, $owner ?? $consumer);
        }
    }

    /** @return list<class-string> the package's own handlers, then the modules' */
    public function handlersFor(string $name): array
    {
        $builtIn = [
            ShadowChanged::NAME => [SyncShadows::class],
            ShadowWanted::NAME => [AnnounceShadowSource::class],
        ];

        return [...($builtIn[$name] ?? []), ...$this->config->getHandlers($name)];
    }

    public function guarded(?string $stream = null): bool
    {
        $stream ??= $this->config->getDefaultStream();

        return $this->config->getGuard()
            ?? ($this->config->usesOutbox($stream) || $this->transports->stream($stream) instanceof RedeliversEnvelopes);
    }

    /** @param class-string $class */
    private function runGuarded(string $class, Envelope $envelope): void
    {
        $module = $this->registry->forClass($class)
            ?? throw ModuleException::outsideModule($class);

        $connection = $this->db->connection($module->connection());

        $connection->transaction(function () use ($connection, $class, $envelope, $module): void {
            $claimed = $connection->table('event_consumptions')->insertOrIgnore([
                'event_id' => $envelope->id,
                'handler' => $class,
                'consumed_at' => Date::now(),
            ]);

            if ($claimed === 0) {
                return; // already handled — the replay stops here
            }

            $this->run($class, $envelope, $module);
        });
    }

    /**
     * @param  class-string  $class
     * @param  Module|null  $module  the handler's own module, or the consumer's for one of the package
     */
    private function run(string $class, Envelope $envelope, ?Module $module): void
    {
        $handler = $this->container->make($class);

        if (! $handler instanceof Handler) {
            throw ConfigurationException::invalidHandler($class, Handler::class);
        }

        $payload = $this->versions->lift($envelope);

        $this->context->within(
            $module,
            fn () => $this->withContext($envelope->headers, static fn () => $handler->handle($envelope->name, $payload)),
        );
    }

    /**
     * Runs the handler under the emitter's propagated context, then puts the caller's back.
     *
     * @param  array<string, mixed>  $headers
     */
    private function withContext(array $headers, callable $callback): void
    {
        $previous = Context::only(array_keys($headers));
        Context::add($headers);

        try {
            $callback();
        } finally {
            Context::forget(array_keys($headers));
            Context::add($previous);
        }
    }
}
