<?php

declare(strict_types=1);

namespace Modulith\Console\Commands;

use Illuminate\Console\Command;
use Modulith\Contracts\Stream\Bus;
use Modulith\Events\ShadowWanted;
use Modulith\Services\Modules\ModuleRegistry;
use Modulith\Services\Shadows\ShadowRegistry;

/** Run by a module keeping copies: asks the owner of each source table to announce what it holds. */
final class WantShadows extends Command
{
    protected $signature = 'modulith:shadows:want';

    protected $description = 'Ask the owners of every source table copied here to announce their rows.';

    public function handle(ShadowRegistry $catalog, ModuleRegistry $registry, Bus $bus): int
    {
        foreach ($catalog->localShadows() as $shadow) {
            $keeper = $registry->forClass($shadow);
            $owner = $registry->get($shadow::owner());

            if ($keeper !== null) {
                $bus->emit(new ShadowWanted($keeper->name, $owner->name, $shadow::sourceTable()));
                $this->line("→ {$keeper->name} wants {$shadow::sourceTable()}");
            }
        }

        return self::SUCCESS;
    }
}
