<?php

declare(strict_types=1);

namespace Modulith\Services\Stream;

use Modulith\Contracts\Stream\Versioned;
use Modulith\Data\Envelope;
use Modulith\Exceptions\ConfigurationException;
use Modulith\Exceptions\ModuleException;

/** Lifts the payload of an envelope to the version this process reads, so a handler only ever sees the current shape. */
final class PayloadVersions
{
    /** @var array<string, class-string<Versioned>> event name => the payload class that declares its versions */
    private array $payloads = [];

    /** @param array<string, class-string> $payloads */
    public function add(array $payloads): void
    {
        foreach ($payloads as $name => $class) {
            if (! is_subclass_of($class, Versioned::class)) {
                throw ConfigurationException::unversionedPayload($class, Versioned::class);
            }

            $this->payloads[$name] = $class;
        }
    }

    /** The version of $name this process reads: 1 until its payload class declares another. */
    public function current(string $name): int
    {
        return isset($this->payloads[$name]) ? $this->payloads[$name]::version() : 1;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ModuleException when the envelope is newer than this process: its consumer has to be deployed first
     */
    public function lift(Envelope $envelope): array
    {
        $current = $this->current($envelope->name);

        if ($envelope->version > $current) {
            throw ModuleException::eventVersionAhead($envelope->name, $envelope->version, $current);
        }

        $payload = $envelope->payload;

        for ($version = $envelope->version; $version < $current; $version++) {
            $payload = $this->payloads[$envelope->name]::upcast($version, $payload);
        }

        return $payload;
    }
}
