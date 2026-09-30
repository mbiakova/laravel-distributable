<?php

declare(strict_types=1);

namespace Modulith\Services\Modules;

use Modulith\Contracts\Modules\Source;
use Modulith\Data\Module;

/** The module list modulith:cache wrote, read back instead of scanning the tree. */
final readonly class CachedSource implements Source
{
    public function __construct(private DiscoveryCache $cache) {}

    /** @return list<Module> */
    public function modules(): array
    {
        return array_map(Module::fromArray(...), $this->cache->load()['modules'] ?? []);
    }
}
