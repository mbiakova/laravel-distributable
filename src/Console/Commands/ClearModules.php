<?php

declare(strict_types=1);

namespace Modulith\Console\Commands;

use Illuminate\Console\Command;
use Modulith\Services\Modules\DiscoveryCache;

final class ClearModules extends Command
{
    protected $signature = 'modulith:clear';

    protected $description = 'Remove the modules cache file.';

    public function handle(DiscoveryCache $cache): int
    {
        $cache->clear();

        $this->components->info('Modules cache cleared.');

        return self::SUCCESS;
    }
}
