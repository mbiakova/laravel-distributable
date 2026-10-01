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
    protected $signature = 'modulith:shadows:want
        {--keepers=* : Only these modules ask for their copies (default: every local keeper)}
        {--sources=* : Only these source tables (default: every one they copy)}';

    protected $description = 'Ask the owners of the source tables copied here to announce their rows.';

    public function handle(ShadowRegistry $catalog, ModuleRegistry $registry, Bus $bus): int
    {
        $keepers = (array) $this->option('keepers');
        $sources = (array) $this->option('sources');

        foreach ($catalog->localShadows() as $shadow) {
            $keeper = $registry->forClass($shadow);

            if ($keeper === null
                || ($keepers !== [] && ! in_array($keeper->name, $keepers, true))
                || ($sources !== [] && ! in_array($shadow::sourceTable(), $sources, true))) {
                continue;
            }

            $bus->emit(new ShadowWanted($keeper->name, $registry->get($shadow::owner())->name, $shadow::sourceTable()));
            $this->line("→ {$keeper->name} wants {$shadow::sourceTable()}");
        }

        return self::SUCCESS;
    }
}
