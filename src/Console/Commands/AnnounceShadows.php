<?php

declare(strict_types=1);

namespace Modulith\Console\Commands;

use Illuminate\Console\Command;
use Modulith\Services\Shadows\ShadowRegistry;

/** Run by the owner of a source table: every row goes out again, for copies created after it. */
final class AnnounceShadows extends Command
{
    protected $signature = 'modulith:shadows:announce
        {source : The source table, e.g. iam_users}
        {--for=* : Only these keeper modules update their copy (default: all of them)}';

    protected $description = 'Announce every row of a source table to the modules keeping a copy of it.';

    public function handle(ShadowRegistry $catalog): int
    {
        $source = $catalog->sourceOf((string) $this->argument('source'));

        if ($source === null) {
            $this->error('No module running here owns that table as a shadow source.');

            return self::FAILURE;
        }

        $this->line('→ announced '.$source->announceAll(keepers: array_values(array_map(strval(...), (array) $this->option('for')))));

        return self::SUCCESS;
    }
}
