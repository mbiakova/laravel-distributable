<?php

declare(strict_types=1);

namespace Distributable\Services\Modules;

use Microservices\Contracts\Colocation;
use Microservices\Models\ShadowModel;
use Microservices\Services\Shadows\ShadowRegistry;

/** The copies and sources of each module, read from distributable:cache when it ran, scanned otherwise. */
final class CachedShadowRegistry extends ShadowRegistry
{
    public function __construct(Colocation $colocation, private readonly DiscoveryCache $cache)
    {
        parent::__construct($colocation);
    }

    /** @return list<class-string<ShadowModel>> */
    protected function kept(string $service): array
    {
        /** @var list<class-string<ShadowModel>> */
        return $this->cache->load()['shadows'][$service] ?? parent::kept($service);
    }

    /** @return list<class-string> */
    protected function sources(string $service): array
    {
        return $this->cache->load()['sources'][$service] ?? parent::sources($service);
    }
}
