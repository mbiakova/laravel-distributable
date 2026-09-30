<?php

declare(strict_types=1);

namespace Modulith\Console\Commands;

use Illuminate\Console\Command;
use Modulith\Data\Module;
use Modulith\Migrations\ShadowMigration;
use Modulith\Services\ModuleRegistry;
use Modulith\Services\ShadowRegistry;

/**
 * Migrates each locally-loaded module into ITS own database, on the module's owning role
 * ({module}_owner — the role that owns the tables and may run DDL, where the runtime role is
 * least-privileged; on PostgreSQL it is also the role that installs and bypasses RLS policies).
 * Nothing here is engine-specific: a "database per module" is N Laravel connections, whether
 * they point at N databases, N schemas of one database, or N servers. Per module:
 *   - the framework migrations (database/migrations: users, jobs, …) run in EVERY module
 *     database, so each base is self-contained for queue/system tables;
 *   - the module's own migrations ({module}/database/migrations) run only in its database.
 *
 * `migrate --database={owner}` sets that connection as the default for the run (and pins the
 * `migrations` repository to it), so plain `Schema::create()` lands in the right base and each
 * base tracks its own migration history — no per-migration connection wiring needed.
 */
final class Migrate extends Command
{
    protected $signature = 'modulith:migrate {--pretend : Dump the SQL that would run instead of executing it}';

    protected $description = 'Run framework + per-module migrations in each local module database, on its owning role.';

    public function handle(ModuleRegistry $registry, ShadowRegistry $catalog): int
    {
        foreach ($registry->local() as $module) {
            if (! $module->hasDatabase) {
                continue;
            }

            $owner = $module->ownerConnection();
            $this->info("→ {$module->name} (".config("database.connections.{$owner}.database").')');

            $paths = [
                // The kernel's own infra tables (outbox, consumption guard) land in every
                // module database — no vendor:publish to ask of the consumer.
                dirname(__DIR__, 3).'/database/migrations',
                database_path('migrations'),
            ];

            $moduleMigrations = $module->path().'/database/migrations';
            if (is_dir($moduleMigrations)) {
                $paths[] = $moduleMigrations;
            }

            $paths = [...$paths, ...$this->shadowMigrations($module, $registry, $catalog)];
            ShadowMigration::$keeper = $module->name;

            // One run over every path, so migrations interleave by date across kernel, app, module and shadows.
            $exitCode = $this->migrate($owner, $paths);

            if ($exitCode !== self::SUCCESS) {
                return $exitCode;
            }
        }

        return self::SUCCESS;
    }

    /**
     * The shadow migrations the owners of the copies this module keeps publish in database/shadows/.
     *
     * @return list<string>
     */
    private function shadowMigrations(Module $module, ModuleRegistry $registry, ShadowRegistry $catalog): array
    {
        $files = [];

        foreach ($catalog->localShadows() as $shadow) {
            $parent = get_parent_class($shadow);
            $owner = $parent === false ? null : $registry->forClass($parent);

            if ($registry->forClass($shadow)?->name !== $module->name || $owner === null) {
                continue;
            }

            $files = [...$files, ...(glob($owner->path().'/database/shadows/*_'.$shadow::sourceTable().'_shadow*.php') ?: [])];
        }

        sort($files);

        return $files;
    }

    /** @param list<string> $paths */
    private function migrate(string $connection, array $paths): int
    {
        return $this->call('migrate', array_filter([
            '--database' => $connection,
            '--path' => $paths,
            '--realpath' => true,
            '--force' => true,
            '--pretend' => $this->option('pretend') ? true : null,
        ], fn (mixed $value): bool => $value !== null));
    }
}
